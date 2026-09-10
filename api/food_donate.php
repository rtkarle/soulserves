<?php
/**
 * SoulServe — Food Donation Handler
 * Image is OPTIONAL. All fields except image are handled gracefully.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
csrf_verify();

$donor_email = $_SESSION['user_email'];

/* ── Image upload — OPTIONAL, never blocks submission ── */
$uploadDir = __DIR__ . '/../uploads/';
$dbPath    = null;
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $up = secure_upload($_FILES['image'], $uploadDir, 'food');
    if ($up) $dbPath = $up;
    else error_log("[food_donate] Image upload failed for $donor_email");
}

/* ── Fields — support both old (prepared_at) and new (food_time) field names ── */
$food_time      = trim($_POST['food_time']      ?? trim($_POST['prepared_at'] ?? '')) ?: null;
$safe_hours     = (int)($_POST['safe_hours']    ?? 0) ?: null;
$quantity       = trim($_POST['quantity']       ?? '');
$priority       = in_array($_POST['priority']??'', ['low','medium','high']) ? $_POST['priority'] : 'medium';
$pickup_address = trim($_POST['pickup_address'] ?? '');
$contact        = trim($_POST['contact']        ?? '');
$notes          = trim($_POST['notes']          ?? '');
$pickup_date    = trim($_POST['pickup_date']    ?? '') ?: null;

if (!$quantity || !$pickup_address || !$contact) {
    header("Location: ../donor/donate.php?error=fields"); exit;
}

/* ── Ensure donation_id column exists ── */
try {
    $chk = $conn->query("SHOW COLUMNS FROM food_donations LIKE 'donation_id'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE food_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id");
        try { $conn->query("ALTER TABLE food_donations ADD UNIQUE KEY uq_food_don_id (donation_id)"); } catch(Throwable $e2){}
    }
} catch (Throwable $e) {}

/* ── Insert ── */
try {
    $stmt = $conn->prepare(
        "INSERT INTO food_donations
         (donor_email,food_time,safe_hours,quantity,priority,pickup_address,contact,image,notes,pickup_date,status,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,'pending',NOW())"
    );
    $qty_str   = (string)$quantity;
    $sh_str    = $safe_hours ? (string)$safe_hours : null;
    $stmt->bind_param("ssssssssss",
        $donor_email, $food_time, $sh_str, $qty_str,
        $priority, $pickup_address, $contact, $dbPath, $notes, $pickup_date
    );
    if (!$stmt->execute()) {
        error_log("[food_donate] Insert failed: " . $stmt->error);
        header("Location: ../donor/donate.php?error=server"); exit;
    }
    $new_id = (int)$conn->insert_id;
} catch (Throwable $e) {
    error_log("[food_donate] Exception: " . $e->getMessage());
    header("Location: ../donor/donate.php?error=server"); exit;
}

/* ── Generate Donation ID ── */
$donation_id = 'DON-FOOD-' . str_pad($new_id, 6, '0', STR_PAD_LEFT);
try {
    $upd = $conn->prepare("UPDATE food_donations SET donation_id=? WHERE id=?");
    $upd->bind_param("si", $donation_id, $new_id);
    $upd->execute();
} catch (Throwable $e) {}

/* ── Email notification (non-fatal) ── */
try {
    require_once __DIR__ . '/../config/mail.php';
    $nr = $conn->prepare("SELECT name FROM register WHERE email=?");
    $nr->bind_param("s", $donor_email); $nr->execute();
    $donor_name = $nr->get_result()->fetch_assoc()['name'] ?? 'Donor';
    sendDonationReceived($donor_email, $donor_name, 'food', $quantity . ' units', $pickup_address);
} catch (Throwable $e) { error_log("[food_donate] Mail error: " . $e->getMessage()); }

/* ── Invalidate AI cache ── */
try { require_once __DIR__ . '/../api/ai_engine.php'; ai_cache_clear(); } catch (Throwable $e) {}

header("Location: ../donor/donor_dashboard.php?success=food&don_id=" . urlencode($donation_id));
exit;
