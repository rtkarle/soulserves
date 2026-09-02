<?php
/**
 * Adhaar – Update Donation Status
 * Handles all status transitions for food_donations & cloth_donations.
 * When status = 'delivered': accepts proof image upload, saves to DB,
 * and sends donor an email with the embedded proof photo.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin     = isset($_SESSION['admin_id']);
$isVolunteer = isset($_SESSION['user_email']);

if (!$isAdmin && !$isVolunteer) {
    header("Location: ../auth/login.php"); exit;
}

csrf_verify();

$id     = (int)($_POST['id']    ?? 0);
$status = trim($_POST['status'] ?? '');
$table  = trim($_POST['table']  ?? 'food_donations');

$allowed_statuses = ['accepted','rejected','scheduled','out_for_pickup','picked_up','delivered'];
$allowed_tables   = ['food_donations','cloth_donations'];

if (!$id || !in_array($status, $allowed_statuses) || !in_array($table, $allowed_tables)) {
    http_response_code(400);
    $ref = $_SERVER['HTTP_REFERER'] ?? '../donor/donor_dashboard.php';
    header("Location: $ref"); exit;
}

$row = $conn->query("SELECT donor_email, volunteer_email FROM `$table` WHERE id=$id")->fetch_assoc();
if (!$row) {
    http_response_code(404);
    $ref = $_SERVER['HTTP_REFERER'] ?? '../donor/donor_dashboard.php';
    header("Location: $ref"); exit;
}

$donorEmail      = $row['donor_email']    ?? '';
$volunteerEmail  = $row['volunteer_email'] ?? ($_SESSION['user_email'] ?? '');
$donationType    = ($table === 'food_donations') ? 'food' : 'cloth';
$details         = [];
$proof_image_url = null;  // absolute URL for email embedding

// ── Handle each status ────────────────────────────────────────
if ($status === 'scheduled') {
    $pickup_date = trim($_POST['pickup_date']     ?? '');
    $pickup_time = trim($_POST['pickup_time']     ?? '');
    $vol_email   = trim($_POST['volunteer_email'] ?? '');

    $stmt = $conn->prepare("UPDATE `$table` SET status=?, pickup_date=?, pickup_time=?, volunteer_email=? WHERE id=?");
    $stmt->bind_param("ssssi", $status, $pickup_date, $pickup_time, $vol_email, $id);

    $details = [
        'pickup_date'     => $pickup_date,
        'pickup_time'     => $pickup_time,
        'volunteer_email' => $vol_email,
    ];

} elseif ($status === 'delivered') {

    // ── Proof image upload ──────────────────────────────────
    $proof_path = null;
    if (!empty($_FILES['delivery_proof']['name'])) {
        $upload_dir = __DIR__ . '/../uploads/';
        $proof_path = secure_upload($_FILES['delivery_proof'], $upload_dir, 'proof');
    }

    // Optional extra fields
    $beneficiary_count = (int)($_POST['beneficiary_count'] ?? 0);
    $delivery_note     = trim($_POST['delivery_note'] ?? '');

    // Build dynamic SQL — include delivery_proof if uploaded
    if ($proof_path) {
        $stmt = $conn->prepare("UPDATE `$table` SET status=?, delivery_proof=? WHERE id=?");
        $stmt->bind_param("ssi", $status, $proof_path, $id);
    } else {
        $stmt = $conn->prepare("UPDATE `$table` SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
    }

    // Build absolute URL for email
    if ($proof_path) {
        $proof_image_url = rtrim(APP_URL, '/') . '/' . $proof_path;
    }

    $details = [
        'volunteer_email'   => $volunteerEmail,
        'proof_image_url'   => $proof_image_url,
        'beneficiary_count' => $beneficiary_count ?: null,
        'delivery_note'     => $delivery_note ?: null,
    ];

} else {
    $stmt = $conn->prepare("UPDATE `$table` SET status=? WHERE id=?");
    $stmt->bind_param("si", $status, $id);
}

if (!$stmt->execute()) die("DB Error: " . $stmt->error);

// Send email notification to donor
if ($donorEmail) {
    sendStatusNotification($donorEmail, $donationType, $status, $details);
}

// Redirect back to appropriate dashboard
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if (strpos($ref, 'distribution_system') !== false) {
    header("Location: ../admin/distribution_system.php");
} elseif ($isAdmin) {
    header("Location: ../admin/admin_dashboard.php");
} else {
    header("Location: ../volunteer/volunteer_dashboard.php");
}
exit;
