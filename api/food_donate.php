<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
csrf_verify();

$donor_email = $_SESSION['user_email'];

$uploadDir = __DIR__ . '/../uploads/';
$dbPath = secure_upload($_FILES['image'] ?? [], $uploadDir, 'food');
if (!$dbPath) { header("Location: ../donor/donate.php?error=upload"); exit; }

$prepared_at    = trim($_POST['prepared_at'] ?? '');
$safe_hours     = (int)($_POST['safe_hours']  ?? 0);
$quantity       = (int)($_POST['quantity']    ?? 0);
$priority       = trim($_POST['priority']     ?? 'medium');
$pickup_address = trim($_POST['pickup_address'] ?? '');
$contact        = trim($_POST['contact']       ?? '');

if (!$prepared_at || !$safe_hours || !$quantity || !$pickup_address || !$contact) {
    header("Location: ../donor/donate.php?error=fields"); exit;
}

/* ── Insert row first to get auto-increment id ── */
$stmt = $conn->prepare(
    "INSERT INTO food_donations
     (donor_email,food_time,safe_hours,quantity,priority,pickup_address,contact,image,status,created_at)
     VALUES (?,?,?,?,?,?,?,?,'pending',NOW())"
);
$stmt->bind_param("ssiissss",
    $donor_email, $prepared_at, $safe_hours, $quantity,
    $priority, $pickup_address, $contact, $dbPath
);
if (!$stmt->execute()) {
    error_log("[food_donate] DB insert failed: " . $stmt->error . " | donor: $donor_email");
    header("Location: ../donor/donate.php?error=server"); exit;
}
$new_id = (int)$conn->insert_id;

/* ── Generate unique donation_id  e.g. DON-FOOD-000042 ── */
$donation_id = 'DON-FOOD-' . str_pad($new_id, 6, '0', STR_PAD_LEFT);

/* ── Add donation_id column if not yet present (safe ALTER) ── */
$conn->query("ALTER TABLE food_donations ADD COLUMN IF NOT EXISTS donation_id VARCHAR(30) DEFAULT NULL AFTER id");
$conn->query("ALTER TABLE food_donations ADD UNIQUE KEY IF NOT EXISTS uq_food_don_id (donation_id)");

$upd = $conn->prepare("UPDATE food_donations SET donation_id=? WHERE id=?");
$upd->bind_param("si", $donation_id, $new_id);
$upd->execute();

/* ── Notify donor ── */
require_once __DIR__ . '/../config/mail.php';
$nr = $conn->prepare("SELECT name FROM register WHERE email=?");
$nr->bind_param("s", $donor_email); $nr->execute();
$donor_name = $nr->get_result()->fetch_assoc()['name'] ?? 'Donor';
sendDonationReceived($donor_email, $donor_name, 'food', $quantity . ' units', $pickup_address);

/* ── Invalidate AI cache so dashboard reflects new donation immediately ── */
require_once __DIR__ . '/../api/ai_engine.php';
ai_cache_clear();

header("Location: ../donor/donor_dashboard.php?success=food&don_id=" . urlencode($donation_id));
exit;
