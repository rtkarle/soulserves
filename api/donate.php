<?php
/**
 * SoulServe — Unified Donation Handler
 * Handles all donation categories: food, clothes, study_material,
 * school_supplies, toys, medicines, electronics, furniture, other
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_email'])) {
    header("Location: ../auth/login.php"); exit;
}
csrf_verify();

$donor_email = $_SESSION['user_email'];

/* ── Ensure donations table exists ── */
try {
    $conn->query("CREATE TABLE IF NOT EXISTS donations (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        donation_id     VARCHAR(30)  NOT NULL UNIQUE,
        donor_email     VARCHAR(180) NOT NULL,
        category        VARCHAR(30)  NOT NULL DEFAULT 'other',
        quantity        VARCHAR(100) NOT NULL DEFAULT '1',
        description     TEXT,
        condition_type  VARCHAR(20)  DEFAULT 'good',
        pickup_address  TEXT NOT NULL,
        contact         VARCHAR(20)  NOT NULL,
        pickup_date     DATE,
        pickup_time     TIME,
        image           VARCHAR(400),
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
        subject_grade   VARCHAR(100),
        book_count      INT DEFAULT NULL,
        expiry_date     DATE,
        medicine_type   VARCHAR(100),
        device_type     VARCHAR(100),
        working_status  VARCHAR(30) DEFAULT 'working',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_donor    (donor_email),
        INDEX idx_status   (status),
        INDEX idx_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log("[donate] Table create failed: " . $e->getMessage());
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
$priority       = in_array($_POST['priority'] ?? '', ['low','medium','high']) ? $_POST['priority'] : 'medium';

if (!$quantity || !$description || !$pickup_address || !$contact) {
    header("Location: ../donor/donate.php?error=fields"); exit;
}

/* ── Category-specific fields ── */
$food_time     = null;
$safe_hours    = null;
$cloth_type    = null;
$is_clean      = 1;
$subject_grade = null;
$book_count    = null;
$expiry_date   = null;
$medicine_type = null;
$device_type   = null;
$working_status= 'working';

switch ($category) {
    case 'food':
        $food_time  = trim($_POST['food_time']   ?? '') ?: null;
        $safe_hours = (int)($_POST['safe_hours'] ?? 0) ?: null;
        if (!$food_time || !$safe_hours) {
            header("Location: ../donor/donate.php?error=fields"); exit;
        }
        break;
    case 'clothes':
        $cloth_type = trim($_POST['cloth_type'] ?? '');
        $is_clean   = isset($_POST['is_clean']) ? 1 : 0;
        break;
    case 'study_material':
    case 'school_supplies':
    case 'toys':
        $subject_grade = trim($_POST['subject_grade'] ?? '') ?: null;
        $book_count    = (int)($_POST['book_count'] ?? 0) ?: null;
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

/* ── Image upload (optional for all categories) ── */
$uploadDir = __DIR__ . '/../uploads/';
$image = null;
if (!empty($_FILES['image']['name'])) {
    $image = secure_upload($_FILES['image'], $uploadDir, $category);
    if (!$image) {
        header("Location: ../donor/donate.php?error=upload"); exit;
    }
}

/* ── Insert into donations table ── */
try {
    $stmt = $conn->prepare(
        "INSERT INTO donations
         (donor_email,category,quantity,description,condition_type,pickup_address,
          contact,pickup_date,image,status,notes,priority,food_time,safe_hours,
          cloth_type,is_clean,subject_grade,book_count,expiry_date,medicine_type,
          device_type,working_status,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    );
    $stmt->bind_param(
        "ssssssssssssissisiisss",
        $donor_email, $category, $quantity, $description, $condition_type,
        $pickup_address, $contact, $pickup_date, $image, $notes, $priority,
        $food_time, $safe_hours, $cloth_type, $is_clean, $subject_grade,
        $book_count, $expiry_date, $medicine_type, $device_type, $working_status
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
$prefix_map = [
    'food'            => 'FOOD',
    'clothes'         => 'CLO',
    'study_material'  => 'STDY',
    'school_supplies' => 'SCHL',
    'toys'            => 'TOY',
    'medicines'       => 'MED',
    'electronics'     => 'ELEC',
    'furniture'       => 'FURN',
    'other'           => 'OTH',
];
$pfx = $prefix_map[$category] ?? 'DON';
$donation_id = 'DON-' . $pfx . '-' . str_pad($new_id, 6, '0', STR_PAD_LEFT);

$conn->prepare("UPDATE donations SET donation_id=? WHERE id=?")
     ->bind_param("si", $donation_id, $new_id) || null;
$upd = $conn->prepare("UPDATE donations SET donation_id=? WHERE id=?");
$upd->bind_param("si", $donation_id, $new_id);
$upd->execute();

/* ── Send email notification ── */
try {
    require_once __DIR__ . '/../config/mail.php';
    $nr = $conn->prepare("SELECT name FROM register WHERE email=?");
    $nr->bind_param("s", $donor_email); $nr->execute();
    $donor_name = $nr->get_result()->fetch_assoc()['name'] ?? 'Donor';
    sendDonationReceived($donor_email, $donor_name, $category, $quantity, $pickup_address);
} catch (Throwable $e) {
    error_log("[donate] Mail failed: " . $e->getMessage());
}

/* ── Invalidate AI cache ── */
try {
    require_once __DIR__ . '/../api/ai_engine.php';
    ai_cache_clear();
} catch (Throwable $e) {}

header("Location: ../donor/donate.php?success=1&don_id=" . urlencode($donation_id));
exit;
