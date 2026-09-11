<?php
/**
 * SoulServe — Official Donation Acknowledgement Receipt
 * Generates an elegant, printable receipt for any donation.
 * Supports: food_donations, cloth_donations, and unified donations.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_email = $_SESSION['user_email'] ?? '';
$is_admin   = isset($_SESSION['admin_id']);

if (!$user_email && !$is_admin) {
    header("Location: ../auth/login.php");
    exit;
}

$raw_id = trim($_GET['id'] ?? '');
$type   = strtolower(trim($_GET['type'] ?? ''));

if (!$raw_id) {
    die("Invalid Receipt Request. Missing Donation ID.");
}

$clean_num_id = (int)preg_replace('/[^0-9]/', '', $raw_id);
$donation = null;
$source_table = '';

// 1. Try finding in unified donations table
if ($conn->query("SHOW TABLES LIKE 'donations'")->num_rows > 0) {
    $stmt = $conn->prepare("SELECT * FROM donations WHERE (id = ? OR donation_id = ?) " . ($is_admin ? "" : "AND donor_email = ?") . " LIMIT 1");
    if ($is_admin) {
        $stmt->bind_param("is", $clean_num_id, $raw_id);
    } else {
        $stmt->bind_param("iss", $clean_num_id, $raw_id, $user_email);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $donation = $res->fetch_assoc();
        $source_table = 'donations';
    }
}

// 2. Try food_donations if not found
if (!$donation && in_array($type, ['food', '', 'all']) && $conn->query("SHOW TABLES LIKE 'food_donations'")->num_rows > 0) {
    $stmt = $conn->prepare("SELECT *, 'food' AS category FROM food_donations WHERE (id = ? OR donation_id = ?) " . ($is_admin ? "" : "AND donor_email = ?") . " LIMIT 1");
    if ($is_admin) {
        $stmt->bind_param("is", $clean_num_id, $raw_id);
    } else {
        $stmt->bind_param("iss", $clean_num_id, $raw_id, $user_email);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $donation = $res->fetch_assoc();
        $source_table = 'food_donations';
    }
}

// 3. Try cloth_donations if not found
if (!$donation && in_array($type, ['cloth', 'clothes', '', 'all']) && $conn->query("SHOW TABLES LIKE 'cloth_donations'")->num_rows > 0) {
    $stmt = $conn->prepare("SELECT *, 'clothes' AS category FROM cloth_donations WHERE (id = ? OR donation_id = ?) " . ($is_admin ? "" : "AND donor_email = ?") . " LIMIT 1");
    if ($is_admin) {
        $stmt->bind_param("is", $clean_num_id, $raw_id);
    } else {
        $stmt->bind_param("iss", $clean_num_id, $raw_id, $user_email);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $donation = $res->fetch_assoc();
        $source_table = 'cloth_donations';
    }
}

if (!$donation) {
    http_response_code(404);
    die("Receipt not found or you do not have permission to view this receipt.");
}

// Fetch donor details
$donor_email = $donation['donor_email'] ?? $user_email;
$donor_name = 'Valued Donor';
$donor_phone = $donation['contact'] ?? '';
$u_stmt = $conn->prepare("SELECT name, mobile FROM register WHERE email=? LIMIT 1");
$u_stmt->bind_param("s", $donor_email);
$u_stmt->execute();
$u_res = $u_stmt->get_result()->fetch_assoc();
if ($u_res) {
    $donor_name = $u_res['name'] ?: $donor_name;
    if (!$donor_phone && !empty($u_res['mobile'])) {
        $donor_phone = $u_res['mobile'];
    }
}

$category = strtolower($donation['category'] ?? 'other');
$category_names = [
    'food'            => 'Food & Meals',
    'clothes'         => 'Clothing & Apparel',
    'study_material'  => 'Study Materials & Books',
    'school_supplies' => 'School Supplies & Uniforms',
    'toys'            => 'Toys & Games',
    'medicines'       => 'Medicines & Health Supplies',
    'electronics'     => 'Electronics & Gadgets',
    'furniture'       => 'Furniture & Essentials',
    'other'           => 'Essential Donations'
];
$category_display = $category_names[$category] ?? ucfirst(str_replace('_', ' ', $category));

$don_id = $donation['donation_id'] ?? ('DON-' . strtoupper($category) . '-' . str_pad($donation['id'], 6, '0', STR_PAD_LEFT));
$created_date = !empty($donation['created_at']) ? date('d M Y, h:i A', strtotime($donation['created_at'])) : date('d M Y');
$receipt_no = 'REC-' . strtoupper(substr(md5($don_id . $created_date), 0, 10));
$status = ucfirst(str_replace('_', ' ', $donation['status'] ?? 'pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Donation Receipt - <?=htmlspecialchars($don_id)?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --primary: #006D77;
    --primary-light: #e6f4f1;
    --secondary: #2E8B57;
    --dark: #102A43;
    --muted: #627D98;
    --border: #E2E8F0;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
  body { background: #f8fafc; color: var(--dark); padding: 40px 20px; line-height: 1.5; }
  .receipt-container {
    max-width: 680px;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(16,42,67,0.08);
    border: 1px solid var(--border);
    overflow: hidden;
  }
  .receipt-header {
    background: linear-gradient(135deg, #006D77, #2E8B57);
    color: #fff;
    padding: 35px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
  }
  .receipt-header h1 { font-size: 24px; font-weight: 900; letter-spacing: -0.5px; }
  .receipt-header p { font-size: 13px; opacity: 0.85; margin-top: 4px; }
  .badge-stamp {
    background: rgba(255,255,255,0.2);
    border: 1.5px solid rgba(255,255,255,0.5);
    border-radius: 12px;
    padding: 8px 16px;
    text-align: right;
    font-size: 12px;
    font-weight: 700;
  }
  .receipt-body { padding: 40px; }
  .receipt-meta-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    padding-bottom: 25px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 25px;
  }
  .meta-group label {
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: block;
    margin-bottom: 5px;
  }
  .meta-group p { font-size: 14px; font-weight: 600; color: var(--dark); }
  .table-section { margin-bottom: 30px; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th {
    background: var(--primary-light);
    color: var(--primary);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    padding: 12px 14px;
    text-align: left;
    border-radius: 8px 8px 0 0;
  }
  td {
    padding: 14px;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
  }
  .status-tag {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    background: #d1fae5;
    color: #065f46;
  }
  .receipt-footer {
    background: #fafaf6;
    padding: 24px 40px;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
  }
  .action-btns {
    display: flex;
    gap: 12px;
    max-width: 680px;
    margin: 20px auto 0;
    justify-content: flex-end;
  }
  .btn {
    padding: 10px 20px;
    border-radius: 50px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: 0.2s;
  }
  .btn-print { background: var(--primary); color: #fff; }
  .btn-print:hover { opacity: 0.9; }
  .btn-back { background: #e2e8f0; color: var(--dark); }
  .btn-back:hover { background: #cbd5e1; }
  @media print {
    body { background: #fff; padding: 0; }
    .receipt-container { box-shadow: none; border: none; border-radius: 0; }
    .action-btns { display: none !important; }
  }
</style>
</head>
<body>

<div class="receipt-container">
  <div class="receipt-header">
    <div>
      <h1>SoulServe</h1>
      <p>Official Donation Acknowledgement</p>
    </div>
    <div class="badge-stamp">
      <div>RECEIPT VERIFIED</div>
      <div style="font-size:11px;font-weight:500;opacity:0.85"><?=htmlspecialchars($receipt_no)?></div>
    </div>
  </div>

  <div class="receipt-body">
    <div class="receipt-meta-grid">
      <div class="meta-group">
        <label>Donor Details</label>
        <p><?=htmlspecialchars($donor_name)?></p>
        <p style="font-weight:400;font-size:13px;color:var(--muted)"><?=htmlspecialchars($donor_email)?></p>
        <?php if($donor_phone): ?>
        <p style="font-weight:400;font-size:13px;color:var(--muted)">Phone: <?=htmlspecialchars($donor_phone)?></p>
        <?php endif; ?>
      </div>
      <div class="meta-group">
        <label>Donation Reference</label>
        <p style="color:var(--primary);font-weight:800;letter-spacing:0.5px"><?=htmlspecialchars($don_id)?></p>
        <p style="font-weight:400;font-size:13px;color:var(--muted)">Date: <?=htmlspecialchars($created_date)?></p>
        <p style="margin-top:6px"><span class="status-tag"><?=htmlspecialchars($status)?></span></p>
      </div>
    </div>

    <div class="table-section">
      <table>
        <thead>
          <tr>
            <th>Category</th>
            <th>Quantity</th>
            <th>Condition</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong><?=htmlspecialchars($category_display)?></strong></td>
            <td><?=htmlspecialchars($donation['quantity'] ?? '1 item')?></td>
            <td><?=htmlspecialchars(ucfirst($donation['condition_type'] ?? 'Good'))?></td>
            <td>
              <?=htmlspecialchars($donation['description'] ?? 'Surplus items donated in good faith')?>
              <?php if(!empty($donation['pickup_address'])): ?>
              <div style="margin-top:6px;font-size:11px;color:var(--muted)">
                <strong>Pickup:</strong> <?=htmlspecialchars($donation['pickup_address'])?>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div style="background:var(--primary-light);border-left:4px solid var(--primary);padding:14px 18px;border-radius:8px;font-size:12px;color:#065f46">
      <strong>Heartfelt Gratitude:</strong> Thank you for your kindness and generosity. Every donation directly empowers vulnerable families and communities through the SoulServe network.
    </div>
  </div>

  <div class="receipt-footer">
    <div style="font-size:11px;color:var(--muted)">
      <strong>SoulServe Foundation</strong> · Transforming surplus into sustenance.<br>
      Automated electronic receipt generated by SoulServe platform.
    </div>
    <div style="font-size:11px;font-weight:700;color:var(--primary)">
      www.soulserve.org
    </div>
  </div>
</div>

<div class="action-btns">
  <button class="btn btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
  <a href="../donor/donor_dashboard.php" class="btn btn-back">← Back to Dashboard</a>
</div>

</body>
</html>
