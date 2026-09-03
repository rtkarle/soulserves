<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
csrf_verify();

$donor_email = $_SESSION['user_email'];

$uploadDir = __DIR__ . '/../uploads/';
$dbPath = secure_upload($_FILES['image'] ?? [], $uploadDir, 'cloth');
if (!$dbPath) { header("Location: ../donor/donate.php?error=upload"); exit; }

$purchase_time  = trim($_POST['purchase_time']  ?? '');
$quantity       = (int)($_POST['quantity']       ?? 0);
$cloth_type     = trim($_POST['cloth_type']      ?? '');
$condition_type = trim($_POST['condition_type']  ?? 'good');
$is_clean       = (int)(!empty($_POST['is_clean']));
$pickup_address = trim($_POST['pickup_address']  ?? '');
$contact        = trim($_POST['contact']         ?? '');

if (!$quantity || !$cloth_type || !$pickup_address || !$contact) {
    header("Location: ../donor/donate.php?error=fields"); exit;
}

/* ── Insert row first ── */
$stmt = $conn->prepare(
    "INSERT INTO cloth_donations
     (donor_email,purchase_time,quantity,cloth_type,condition_type,is_clean,pickup_address,contact,image,status,created_at)
     VALUES (?,?,?,?,?,?,?,?,?,'pending',NOW())"
);
$stmt->bind_param("ssississs",
    $donor_email, $purchase_time, $quantity, $cloth_type,
    $condition_type, $is_clean, $pickup_address, $contact, $dbPath
);
if (!$stmt->execute()) {
    error_log("[cloth_donate] DB insert failed: " . $stmt->error . " | donor: $donor_email");
    header("Location: ../donor/donate.php?error=server"); exit;
}
$new_id = (int)$conn->insert_id;

/* ── Generate unique donation_id  e.g. DON-CLO-000017 ── */
$donation_id = 'DON-CLO-' . str_pad($new_id, 6, '0', STR_PAD_LEFT);

/* ── Add donation_id column if not yet present ── */
try {
    $col_check = $conn->query("SHOW COLUMNS FROM cloth_donations LIKE 'donation_id'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE cloth_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id");
        $conn->query("ALTER TABLE cloth_donations ADD UNIQUE KEY uq_cloth_don_id (donation_id)");
    }
} catch (Throwable $e) { /* column may already exist — safe to ignore */ }

$upd = $conn->prepare("UPDATE cloth_donations SET donation_id=? WHERE id=?");
$upd->bind_param("si", $donation_id, $new_id);
$upd->execute();

/* ── Notify donor ── */
require_once __DIR__ . '/../config/mail.php';
$nr = $conn->prepare("SELECT name FROM register WHERE email=?");
$nr->bind_param("s", $donor_email); $nr->execute();
$donor_name = $nr->get_result()->fetch_assoc()['name'] ?? 'Donor';
sendDonationReceived($donor_email, $donor_name, 'cloth', $quantity . ' pieces of ' . $cloth_type, $pickup_address);

/* ── Invalidate AI cache so dashboard reflects new donation immediately ── */
require_once __DIR__ . '/../api/ai_engine.php';
ai_cache_clear();

header("Location: ../donor/donor_dashboard.php?success=cloth&don_id=" . urlencode($donation_id));
exit;
