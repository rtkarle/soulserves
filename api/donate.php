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
        pickup_time     TIME DEFAULT NULL,
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

// Make donation_id nullable if existing table had NOT NULL without default
try { $conn->query("ALTER TABLE donations MODIFY COLUMN donation_id VARCHAR(30) DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE donations ADD COLUMN delivery_proof VARCHAR(400) DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE donations ADD COLUMN pickup_time TIME DEFAULT NULL"); } catch (Throwable $e) {}

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

/* ── Sanitize ENUM values to match DB schema ── */
$allowed_conditions = ['new','like_new','good','fair','worn'];
if (!in_array($condition_type, $allowed_conditions, true)) $condition_type = 'good';
// Map like_new → new for older DB schemas
$condition_db = $condition_type === 'like_new' ? 'new' : $condition_type;

$allowed_working = ['working','partially_working','not_working'];
if (!in_array($working_status, $allowed_working, true)) $working_status = 'working';

/* ── Upload images to Cloudinary (up to 3, all optional) ── */
$uploadDir = __DIR__ . '/../uploads/';
$uploaded_images = [];
foreach (['image', 'image2', 'image3'] as $field) {
    if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        /* secure_upload() tries Cloudinary first, local fallback */
        $up = secure_upload($_FILES[$field], $uploadDir, 'don_' . $category);
        if ($up) $uploaded_images[] = $up;
        else error_log("[donate] Image '$field' upload failed for $donor_email");
    }
}
$image_str = !empty($uploaded_images) ? implode(',', $uploaded_images) : null;

/* ── Generate unique donation ID beforehand to guarantee valid NOT NULL column ── */
$pfx_map = [
    'food'=>'FOOD', 'clothes'=>'CLO', 'study_material'=>'STDY',
    'school_supplies'=>'SCHL', 'toys'=>'TOY', 'medicines'=>'MED',
    'electronics'=>'ELEC', 'furniture'=>'FURN', 'other'=>'OTH',
];
$pfx = $pfx_map[$category] ?? 'DON';
$initial_don_id = 'DON-' . $pfx . '-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$don_id_s = mysqli_real_escape_string($conn, $initial_don_id);

/* ── Build INSERT using null-safe helper ── */
/* Convert all values to safe strings; NULL stays NULL */
$me_s   = mysqli_real_escape_string($conn, $donor_email);
$cat_s  = mysqli_real_escape_string($conn, $category);
$qty_s  = mysqli_real_escape_string($conn, substr($quantity, 0, 100));
$desc_s = mysqli_real_escape_string($conn, $description);
$cond_s = mysqli_real_escape_string($conn, $condition_db);
$addr_s = mysqli_real_escape_string($conn, $pickup_address);
$cont_s = mysqli_real_escape_string($conn, substr($contact, 0, 20));
$img_s  = $image_str ? "'" . mysqli_real_escape_string($conn, $image_str) . "'" : 'NULL';
$not_s  = mysqli_real_escape_string($conn, $notes);
$pri_s  = mysqli_real_escape_string($conn, $priority);
$cl_s   = mysqli_real_escape_string($conn, substr($cloth_type ?? '', 0, 80));
$icl    = (int)$is_clean;
$sg_s   = $subject_grade ? "'" . mysqli_real_escape_string($conn, substr($subject_grade, 0, 100)) . "'" : 'NULL';
$med_s  = $medicine_type ? "'" . mysqli_real_escape_string($conn, substr($medicine_type, 0, 100)) . "'" : 'NULL';
$dev_s  = $device_type   ? "'" . mysqli_real_escape_string($conn, substr($device_type, 0, 100))   . "'" : 'NULL';
$wks_s  = mysqli_real_escape_string($conn, $working_status);

/* Nullable date/int fields */
$pd_s  = $pickup_date ? "'" . mysqli_real_escape_string($conn, $pickup_date) . "'"  : 'NULL';
$ft_s  = $food_time   ? "'" . mysqli_real_escape_string($conn, $food_time)   . "'"  : 'NULL';
$exp_s = $expiry_date ? "'" . mysqli_real_escape_string($conn, $expiry_date) . "'"  : 'NULL';
$sh_i  = ($safe_hours  !== null && $safe_hours  !== '') ? (int)$safe_hours  : 'NULL';
$bc_i  = ($book_count  !== null && $book_count  !== '') ? (int)$book_count  : 'NULL';

try {
    $sql = "INSERT INTO donations
        (donation_id, donor_email, category, quantity, description, condition_type,
         pickup_address, contact, pickup_date, image, status,
         notes, priority, food_time, safe_hours, cloth_type,
         is_clean, subject_grade, book_count, expiry_date,
         medicine_type, device_type, working_status, created_at)
        VALUES
        ('$don_id_s','$me_s','$cat_s','$qty_s','$desc_s','$cond_s',
         '$addr_s','$cont_s',$pd_s,$img_s,'pending',
         '$not_s','$pri_s',$ft_s,$sh_i,'$cl_s',
         $icl,$sg_s,$bc_i,$exp_s,
         $med_s,$dev_s,'$wks_s',NOW())";

    $result = $conn->query($sql);
    if (!$result) {
        error_log("[donate] Insert failed: " . $conn->error . " | SQL: " . substr($sql, 0, 200));
        header("Location: ../donor/donate.php?error=server"); exit;
    }
    $new_id = (int)$conn->insert_id;

} catch (Throwable $e) {
    error_log("[donate] Exception: " . $e->getMessage());
    header("Location: ../donor/donate.php?error=server"); exit;
}

/* ── Standardize to clean sequential Donation ID ── */
$donation_id = $initial_don_id;
$seq_donation_id = 'DON-' . $pfx . '-' . str_pad($new_id, 6, '0', STR_PAD_LEFT);
try {
    $upd = $conn->prepare("UPDATE donations SET donation_id=? WHERE id=?");
    if ($upd) {
        $upd->bind_param("si", $seq_donation_id, $new_id);
        if ($upd->execute()) {
            $donation_id = $seq_donation_id;
        }
    }
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
