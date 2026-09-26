<?php
/**
 * SoulServe First-Come, First-Served (FCFS) Task Claiming API
 * Enforces atomic concurrency: only the exact first volunteer to claim is assigned.
 * Subsequent claims receive a polite 'ALREADY_CLAIMED' response.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

if (!isset($_SESSION['user_email'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$vol_email = $_SESSION['user_email'];

// Verify role
$chk = $conn->prepare("SELECT name, role, verified FROM register WHERE email=? AND verified=1");
$chk->bind_param("s", $vol_email);
$chk->execute();
$user = $chk->get_result()->fetch_assoc();

if (!$user || $user['role'] !== 'volunteer') {
    echo json_encode(['success' => false, 'message' => 'Only verified volunteers can claim tasks']);
    exit;
}

$donation_id = (int)($_POST['donation_id'] ?? 0);
$table_input = trim($_POST['table'] ?? 'donations');

$allowed_tables = [
    'donations'       => 'donations',
    'food_donations'  => 'food_donations',
    'cloth_donations' => 'cloth_donations'
];
$table = $allowed_tables[$table_input] ?? 'donations';

if ($donation_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid donation ID']);
    exit;
}

try {
    $conn->begin_transaction();

    // ── ATOMIC FCFS UPDATE ──────────────────────────────────────────────────
    // Only updates if volunteer_email is currently NULL or empty AND status is pending or accepted
    $stmt = $conn->prepare("UPDATE `$table`
                            SET volunteer_email = ?, status = 'scheduled', pickup_date = CURDATE()
                            WHERE id = ? 
                              AND (volunteer_email IS NULL OR volunteer_email = '')
                              AND status IN ('pending', 'accepted')");
    $stmt->bind_param("si", $vol_email, $donation_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        // ── 1. SUCCESS: Volunteer won the race! ─────────────────────────────
        $dtype = ($table === 'food_donations') ? 'food' : (($table === 'cloth_donations') ? 'cloth' : 'donation');
        
        // Log into volunteer_tasks if table exists
        try {
            $vt = $conn->prepare("INSERT INTO volunteer_tasks 
                                  (volunteer_email, donation_type, donation_id, assigned_by, task_status, notes, assigned_at)
                                  VALUES (?, ?, ?, 'self_claimed_fcfs', 'accepted', 'Claimed via FCFS live feed', NOW())");
            if ($vt) {
                $vt->bind_param("ssi", $vol_email, $dtype, $donation_id);
                $vt->execute();
            }
        } catch (Throwable $e) {}

        // Fetch donation record for donor notification
        $cat = 'donation';
        $qty = '';
        $addr = '';
        $donor_email = '';
        $dq = $conn->query("SELECT * FROM `$table` WHERE id = $donation_id")->fetch_assoc();
        if ($dq) {
            $donor_email = $dq['donor_email'] ?? '';
            $cat = $dq['category'] ?? $dtype;
            $qty = $dq['quantity'] ?? '';
            $addr = $dq['pickup_address'] ?? '';
            $don_code = $dq['donation_id'] ?? ("DON-" . $donation_id);
        }

        // Notify donor in notifications table
        if ($donor_email) {
            $notif_title = "Volunteer Assigned! 🚚";
            $notif_msg = "Volunteer {$user['name']} has accepted your pickup for {$qty} ({$cat}). Scheduled for pickup today.";
            $notif_link = "track.php?s=" . urlencode($don_code ?? $donation_id);
            try {
                $ns = $conn->prepare("INSERT INTO notifications (user_email, title, message, type, link) VALUES (?, ?, ?, 'task_claimed', ?)");
                $ns->bind_param("ssss", $donor_email, $notif_title, $notif_msg, $notif_link);
                $ns->execute();
            } catch (Throwable $e) {}

            // Send non-fatal status email to donor
            try {
                sendStatusNotification($donor_email, $dtype, 'scheduled', [
                    'pickup_date'     => date('Y-m-d'),
                    'pickup_time'     => 'Today (Standard hours)',
                    'volunteer_email' => $vol_email
                ]);
            } catch (Throwable $e) {}
        }

        $conn->commit();

        echo json_encode([
            'success'     => true,
            'message'     => 'Task claimed successfully! Assigned to you.',
            'donation_id' => $donation_id,
            'don_code'    => $don_code ?? "DON-{$donation_id}",
            'category'    => $cat,
            'volunteer'   => $user['name']
        ]);
        exit;

    } else {
        // ── 2. ALREADY CLAIMED ──────────────────────────────────────────────
        // Another volunteer was faster and claimed this task!
        $conn->rollback();

        echo json_encode([
            'success' => false,
            'code'    => 'ALREADY_CLAIMED',
            'message' => '⚡ Already Claimed! Another volunteer accepted this task moments ago. Thank you for your willingness to help!'
        ]);
        exit;
    }

} catch (Throwable $e) {
    if ($conn) $conn->rollback();
    error_log("[claim_task] Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while claiming task. Please try again.']);
}
