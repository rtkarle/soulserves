<?php
/**
 * SoulServe — Unified Donation Handler
 * All 9 categories. Multiple images stored as JSON in image column.
 * Completely avoids image2/image3 column dependency.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
csrf_verify();

$donor_email = $_SESSION['user_email'];

/* ── Auto-create/update donations table ── */
try {
    $conn->query("CREATE TABLE IF NOT EXISTS donations (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        donation_id     VARCHAR(30)  UNIQUE,
        donor_email     VARCHAR(180) NOT NULL,
        category        VARCHAR(30)  NOT NULL DEFAULT 'other',
        quantity        VARCHAR(100) NOT NULL DEFAULT '1',
        description     TEXT,
        condition_type  VARCHAR(20)  DEFAULT 'good',
        pickup_address  TEXT NOT NULL,
        contact         VARCHAR(20)  NOT NULL,
        pickup_date     DATE,
        image           VARCHAR(600),
        status          ENUM('pending','accepted','rejected','scheduled',
                             'out_for_pickup','picked_up','delivered')
                        NOT NULL DEFAULT 'pending',
        volunteer_email VARCHAR(180),
        notes           TEXT,
        priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
        food_time       DATETIME,
        safe_hours      INT DEFAULT NULL,
        cloth_type      VARCHAR(80),
        is_clean        TINYINT(1) DEFAULT 1,
        subject_grade   VARCHAR(200),
        book_count      INT DEFAULT NULL,
        expiry_date     DATE,
        medicine_type   VARCHAR(100),
        device_type     VARCHAR(100),
        working_status  VARCHAR(30) DEFAULT 'working',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_donor (donor_email),
        INDEX idx_status (status),
        INDEX idx_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log("[donate] Table: " . $e->getMessage());
}

/* ── Validate category ── */
$allowed_cats = ['food','clothes','study_material','school_supplies',
                 'toys','medicines','electronics','furniture','other'];
$category = trim($_POST['category'] ?? '');
if (!in_array($category, $allowed_cats, true)) {
    header("Location: ../donor/donate.php?error=fields"); exit;
}

/* ── Common fields ── */
$quantity       = trim($_POST['quantity']       ?? '');
$description    = trim($_POST['description']    ?? '');
$pickup_address = trim($_POST['pickup_address'] ?? '');
$contact        = trim($_POST['contact']        ?? '');
$pickup_date    = trim($_POST['pickup_date']    ?? '') ?: null;
$notes          = trim($_POST['notes']          ?? '');
$condition_type = trim($_POST['condition_type'] ?? 'good');
$priority       = in_array($_POST['priority'] ?? '', ['low','medium','high'])
                    ? $_POST['priority'] : 'medium';

if (!$quantity || !$pickup_address || !$contact) {
    header("Location: ../donor/donate.php?error=fields"); exit;
}

/* ── Category-specific fields ── */
$food_time      = null; $safe_hours    = null;
$cloth_type     = null; $is_clean      = 1;
$subject_grade  = null; $book_count    = null;
$expiry_date    = null; $medicine_type = null;
$device_type    = null; $working_status= 'working';

switch ($category) {
    case 'food':
        $food_time  = trim($_POST['food_time'] ?? '') ?: null;
        $safe_hours = trim($_POST['safe_hours'] ?? '') ?: null;
        break;
    case 'clothes':
        $cloth_type = trim($_POST['cloth_for'] ?? '') ?: trim($_POST['cloth_type'] ?? '');
        $is_clean   = isset($_POST['is_clean']) ? 1 : 0;
        $extras = [];
        foreach (['cloth_garment_type','cloth_sizes','cloth_color','cloth_packed',
                  'footwear_type','footwear_sizes'] as $k) {
            if (!empty($_POST[$k])) $extras[] = ucfirst(str_replace('_',' ',$k)) . ': ' . trim($_POST[$k]);
        }
        if (!empty($_POST['cloth_pieces']))   $extras[] = 'Pieces: ' . (int)$_POST['cloth_pieces'];
        if (!empty($_POST['footwear_pairs'])) $extras[] = 'Pairs: '  . (int)$_POST['footwear_pairs'];
        if ($extras) $description .= ($description ? ' | ' : '') . implode(' | ', $extras);
        $subject_grade = trim($_POST['cloth_sizes'] ?? $_POST['footwear_sizes'] ?? '') ?: null;
        break;
    case 'study_material':
    case 'school_supplies':
    case 'toys':
        $subject_grade = trim($_POST['subject_grade'] ?? '') ?: null;
        $book_count    = trim($_POST['book_count']    ?? '') ?: null;
        break;
    case 'medicines':
        $medicine_type = trim($_POST['medicine_type'] ?? '') ?: null;
        $expiry_date   = trim($_POST['expiry_date']   ?? '') ?: null;
        if ($expiry_date && strtotime($expiry_date) <= time()) {
            header("Location: ../donor/donate.php?error=fields"); exit;
        }
        break;
    case 'electronics':
        $device_type    = trim($_POST['device_type']    ?? '') ?: null;
        $working_status = in_array($_POST['working_status'] ?? '',
            ['working','partially_working','not_working'])
            ? $_POST['working_status'] : 'working';
        break;
    case 'furniture':
    case 'other':
        $device_type = trim($_POST['device_type'] ?? '') ?: null;
        break;
}

/* ── Multiple image upload (up to 3) stored as comma-separated URLs ── */
$uploadDir = __DIR__ . '/../uploads/';
$uploaded_images = [];
foreach (['image', 'image2', 'image3'] as $i => $field) {
    if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $up = secure_upload($_FILES[$field], $uploadDir, $category . '_' . ($i+1));
        if ($up) $uploaded_images[] = $up;
        else error_log("[donate] Image '$field' upload failed for $donor_email");
    }
}
// Store as comma-separated string in single image column
$image_str = !empty($uploaded_images) ? implode(',', $uploaded_images) : null;

/* ── Convert nullable ints to strings for all-s binding ── */
$sh_s  = $safe_hours !== null ? (string)$safe_hours : null;
$bc_s  = $book_count !== null ? (string)$book_count : null;
$icl_s = (string)(int)$is_clean;

/* ── INSERT — 21 ? placeholders, 21 vars, 21 × s ── */
try {
    $stmt = $conn->prepare(
        "INSERT INTO donations
         (donor_email, category, quantity, description, condition_type,
          pickup_address, contact, pickup_date, image, status,
          notes, priority, food_time, safe_hours, cloth_type,
          is_clean, subject_grade, book_count, expiry_date,
          medicine_type, device_type, working_status, created_at)
         VALUES
         (?,?,?,?,?,  ?,?,?,?,'pending',  ?,?,?,?,?,  ?,?,?,?,  ?,?,?,NOW())"
    );

    $stmt->bind_param(
        "sssssssssssssssssssss",   // exactly 21 × s
        $donor_email,     //  1
        $category,        //  2
        $quantity,        //  3
        $description,     //  4
        $condition_type,  //  5
        $pickup_address,  //  6
        $contact,         //  7
        $pickup_date,     //  8
        $image_str,       //  9  (comma-sep URLs or null)
        $notes,           // 10
        $priority,        // 11
        $food_time,       // 12
        $sh_s,            // 13
        $cloth_type,      // 14
        $icl_s,           // 15
        $subject_grade,   // 16
        $bc_s,            // 17
        $expiry_date,     // 18
        $medicine_type,   // 19
        $device_type,     // 20
        $working_status   // 21
    );

    if (!$stmt->execute()) {
        error_log("[donate] Insert failed: " . $stmt->error);
        header("Location: ../donor/donate.php?error=server"); exit;
    }
    $new_id = (int)$conn->insert_id;

} catch (Throwable $e) {
    error_log("[donate] Exception: " . $e->getMessage());
    header("Location: ../donor/donate.php?error=server"); exit;
}

/* ── Generate Donation ID ── */
$pfx_map = [
    'food'=>'FOOD', 'clothes'=>'CLO', 'study_material'=>'STDY',
    'school_supplies'=>'SCHL', 'toys'=>'TOY', 'medicines'=>'MED',
    'electronics'=>'ELEC', 'furniture'=>'FURN', 'other'=>'OTH',
];
$donation_id = 'DON-' . ($pfx_map[$category] ?? 'DON') . '-' . str_pad($new_id, 6, '0', STR_PAD_LEFT);

try {
    $upd = $conn->prepare("UPDATE donations SET donation_id=? WHERE id=?");
    $upd->bind_param("si", $donation_id, $new_id);
    $upd->execute();
} catch (Throwable $e) {}

/* ── Email notification (non-fatal) ── */
try {
    require_once __DIR__ . '/../config/mail.php';
    $nr = $conn->prepare("SELECT name FROM register WHERE email=?");
    $nr->bind_param("s", $donor_email); $nr->execute();
    $donor_name = $nr->get_result()->fetch_assoc()['name'] ?? 'Donor';
    sendDonationReceived($donor_email, $donor_name, $category, $quantity, $pickup_address);
} catch (Throwable $e) { error_log("[donate] Mail: " . $e->getMessage()); }

/* ── Invalidate AI cache ── */
try { require_once __DIR__ . '/../api/ai_engine.php'; ai_cache_clear(); } catch (Throwable $e) {}

header("Location: ../donor/donor_dashboard.php?success=" . urlencode($category) . "&don_id=" . urlencode($donation_id));
exit;
