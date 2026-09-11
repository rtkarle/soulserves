<?php
/**
 * SoulServe — Cloth Donation Handler
 * Image is OPTIONAL. All required fields validated gracefully.
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
    $up = secure_upload($_FILES['image'], $uploadDir, 'cloth');
    if ($up) $dbPath = $up;
    else error_log("[cloth_donate] Image upload failed for $donor_email");
}

/* ── Fields — support both old and new field names ── */
$purchase_time  = trim($_POST['purchase_time']  ?? '') ?: null;
$quantity       = trim($_POST['quantity']        ?? '');
$cloth_type     = trim($_POST['cloth_type']      ?? trim($_POST['cloth_for'] ?? ''));
$condition_type = in_array($_POST['condition_type']??'', ['new','like_new','good','fair','worn'])
                    ? $_POST['condition_type'] : 'good';
$condition_db   = ($condition_type === 'like_new') ? 'new' : $condition_type;
$is_clean       = (int)(!empty($_POST['is_clean']));
$pickup_address = trim($_POST['pickup_address']  ?? '');
$contact        = trim($_POST['contact']         ?? '');
$notes          = trim($_POST['notes']           ?? '');
$pickup_date    = trim($_POST['pickup_date']      ?? '') ?: null;

if (!$quantity || !$pickup_address || !$contact) {
    header("Location: ../donor/donate.php?error=fields"); exit;
}

/* ── Ensure donation_id column exists ── */
try {
    $chk = $conn->query("SHOW COLUMNS FROM cloth_donations LIKE 'donation_id'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE cloth_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id");
        try { $conn->query("ALTER TABLE cloth_donations ADD UNIQUE KEY uq_cloth_don_id (donation_id)"); } catch(Throwable $e2){}
    }
} catch (Throwable $e) {}

/* ── Insert ── */
try {
    $stmt = $conn->prepare(
        "INSERT INTO cloth_donations
         (donor_email,purchase_time,quantity,cloth_type,condition_type,is_clean,pickup_address,contact,image,notes,pickup_date,status,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',NOW())"
    );
    $qty_str = (string)$quantity;
    $is_cl   = (string)$is_clean;
    $stmt->bind_param("sssssssssss",
        $donor_email, $purchase_time, $qty_str, $cloth_type,
        $condition_db, $is_cl, $pickup_address, $contact, $dbPath, $notes, $pickup_date
    );
    if (!$stmt->execute()) {
        error_log("[cloth_donate] Insert failed: " . $stmt->error);
        header("Location: ../donor/donate.php?error=server"); exit;
    }
    $new_id = (int)$conn->insert_id;
} catch (Throwable $e) {
    error_log("[cloth_donate] Exception: " . $e->getMessage());
    header("Location: ../donor/donate.php?error=server"); exit;
}

/* ── Generate Donation ID ── */
$donation_id = 'DON-CLO-' . str_pad($new_id, 6, '0', STR_PAD_LEFT);
try {
    $upd = $conn->prepare("UPDATE cloth_donations SET donation_id=? WHERE id=?");
    $upd->bind_param("si", $donation_id, $new_id);
    $upd->execute();
} catch (Throwable $e) {}

/* ── Email notification (non-fatal) ── */
try {
    require_once __DIR__ . '/../config/mail.php';
    $nr = $conn->prepare("SELECT name FROM register WHERE email=?");
    $nr->bind_param("s", $donor_email); $nr->execute();
    $donor_name = $nr->get_result()->fetch_assoc()['name'] ?? 'Donor';
    sendDonationReceived($donor_email, $donor_name, 'cloth', $quantity . ' pieces', $pickup_address);
} catch (Throwable $e) { error_log("[cloth_donate] Mail error: " . $e->getMessage()); }

/* ── Invalidate AI cache ── */
try { require_once __DIR__ . '/../api/ai_engine.php'; ai_cache_clear(); } catch (Throwable $e) {}

header("Location: ../donor/donor_dashboard.php?success=cloth&don_id=" . urlencode($donation_id));
exit;
