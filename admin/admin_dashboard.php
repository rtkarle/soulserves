<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit; }

function table_exists(mysqli $conn, string $table): bool {
    $res = $conn->query("SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    return $res && $res->num_rows > 0;
}

$tab    = $_GET['tab']  ?? 'overview';
$search = trim($_GET['s'] ?? '');

$food_table_exists = table_exists($conn, 'food_donations');
$cloth_table_exists = table_exists($conn, 'cloth_donations');
$contact_table_exists = table_exists($conn, 'contact_messages');

// ── Stats ─────────────────────────────────────────────────────────────────
$stats = [
  'total_users'     => (int)$conn->query("SELECT COUNT(*) c FROM register WHERE verified=1")->fetch_assoc()['c'],
  'donors'          => (int)$conn->query("SELECT COUNT(*) c FROM register WHERE role='donor' AND verified=1")->fetch_assoc()['c'],
  'volunteers'      => (int)$conn->query("SELECT COUNT(*) c FROM register WHERE role='volunteer' AND verified=1")->fetch_assoc()['c'],
  'sellers'         => (int)$conn->query("SELECT COUNT(*) c FROM register WHERE role='seller' AND verified=1")->fetch_assoc()['c'],
  'food_total'      => $food_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM food_donations")->fetch_assoc()['c'] : 0,
  'food_pending'    => $food_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM food_donations WHERE status='pending'")->fetch_assoc()['c'] : 0,
  'food_delivered'  => $food_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM food_donations WHERE status='delivered'")->fetch_assoc()['c'] : 0,
  'cloth_total'     => $cloth_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM cloth_donations")->fetch_assoc()['c'] : 0,
  'cloth_pending'   => $cloth_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE status='pending'")->fetch_assoc()['c'] : 0,
  'cloth_delivered' => $cloth_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE status='delivered'")->fetch_assoc()['c'] : 0,
  'total_products'  => (int)$conn->query("SELECT COUNT(*) c FROM products WHERE is_active=1")->fetch_assoc()['c'],
  'total_orders'    => (int)$conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'],
  'revenue'         => (float)$conn->query("SELECT COALESCE(SUM(total_amount),0) r FROM orders WHERE order_status NOT IN ('cancelled','returned')")->fetch_assoc()['r'],
  'stores'          => (int)$conn->query("SELECT COUNT(*) c FROM seller_stores")->fetch_assoc()['c'],
  'contact_msgs'    => $contact_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM contact_messages")->fetch_assoc()['c'] : 0,
];
$stats['pending_don'] = $stats['food_pending'] + $stats['cloth_pending'];

// ── AI Engine ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../api/ai_engine.php';
$ai           = adhaar_ai();
$ai_recs      = $ai->getAdminRecommendations();
$ai_forecast  = $ai->demandForecast();
$ai_impact    = $ai->predictImpact();

// Weekly chart data
$w_labels = $w_food = $w_cloth = [];
for ($i = 7; $i >= 0; $i--) {
    $from = date('Y-m-d', strtotime("-".($i+1)." weeks"));
    $to   = date('Y-m-d', strtotime("-$i weeks"));
    $wf = $food_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM food_donations WHERE created_at BETWEEN '$from' AND '$to'")->fetch_assoc()['c'] : 0;
    $wc = $cloth_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE created_at BETWEEN '$from' AND '$to'")->fetch_assoc()['c'] : 0;
    $w_labels[] = date('d M', strtotime("-$i weeks"));
    $w_food[]   = $wf; $w_cloth[] = $wc;
}

// Pending donations for task assignment
$food_table_exists = table_exists($conn, 'food_donations');
$cloth_table_exists = table_exists($conn, 'cloth_donations');
$pending_food = $food_table_exists ? $conn->query("SELECT id,donor_email,quantity,pickup_address,priority,created_at FROM food_donations WHERE status='accepted' ORDER BY priority DESC,created_at ASC LIMIT 20")->fetch_all(MYSQLI_ASSOC) : [];
$pending_cloth = $cloth_table_exists ? $conn->query("SELECT id,donor_email,quantity,pickup_address,created_at FROM cloth_donations WHERE status='accepted' ORDER BY created_at ASC LIMIT 20")->fetch_all(MYSQLI_ASSOC) : [];

// Settlements (safe if table doesn't exist yet)
$settlements_exist = table_exists($conn, 'settlements');
$settlements = $settlements_exist ? $conn->query("SELECT s.*, ss.store_name FROM settlements s LEFT JOIN seller_stores ss ON ss.seller_email=s.seller_email ORDER BY s.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC) : [];
$pending_payouts = [];
if ($settlements_exist) {
    $ps = $conn->query("SELECT o.seller_email, ss.store_name, ss.upi_id, ss.bank_account, COUNT(o.id) cnt, SUM(o.total_amount) total FROM orders o JOIN seller_stores ss ON ss.seller_email=o.seller_email WHERE o.order_status='delivered' AND o.payment_status='pending' GROUP BY o.seller_email");
    $pending_payouts = $ps->fetch_all(MYSQLI_ASSOC);
}

// ── Paginated queries ─────────────────────────────────────────────────────
$per = 20;
// ── Unified Donations
$donations_table_exists = table_exists($conn, 'donations');
$dp = max(1,(int)($_GET['dp']??1));
$donations_rows = [];
$donations_total = 0;
$donations_pages = 1;
$don_cat_filter = $_GET['don_cat'] ?? 'all';
$don_status_filter = $_GET['don_status'] ?? 'all';
$allowed_don_cats = ['food','clothes','study_material','school_supplies','toys','medicines','electronics','furniture','other'];
$allowed_don_statuses = ['pending','accepted','rejected','scheduled','out_for_pickup','picked_up','delivered'];
if (!in_array($don_cat_filter, $allowed_don_cats, true)) $don_cat_filter = 'all';
if (!in_array($don_status_filter, $allowed_don_statuses, true)) $don_status_filter = 'all';

if ($donations_table_exists) {
    $don_where = '1=1';
    if ($don_cat_filter !== 'all') $don_where .= " AND category='" . mysqli_real_escape_string($conn,$don_cat_filter) . "'";
    if ($don_status_filter !== 'all') $don_where .= " AND status='" . mysqli_real_escape_string($conn,$don_status_filter) . "'";
    $donations_total = (int)$conn->query("SELECT COUNT(*) c FROM donations WHERE $don_where")->fetch_assoc()['c'];
    $donations_pages = max(1, (int)ceil($donations_total / $per));
    $dq = $conn->query("SELECT * FROM donations WHERE $don_where ORDER BY created_at DESC LIMIT $per OFFSET " . (($dp-1)*$per));
    $donations_rows = $dq ? $dq->fetch_all(MYSQLI_ASSOC) : [];
}
$stats['donations_total']   = $donations_total;
$stats['donations_pending']  = $donations_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM donations WHERE status='pending'")->fetch_assoc()['c'] : 0;
$stats['donations_delivered']= $donations_table_exists ? (int)$conn->query("SELECT COUNT(*) c FROM donations WHERE status='delivered'")->fetch_assoc()['c'] : 0;
$stats['pending_don'] += $stats['donations_pending'];

$don_cat_icons = [
    'food'=>'🍱','clothes'=>'👕','study_material'=>'📚','school_supplies'=>'🎒',
    'toys'=>'🧸','medicines'=>'💊','electronics'=>'📱','furniture'=>'🪑','other'=>'📦'
];
$fp  = max(1,(int)($_GET['fp']??1));
$cp  = max(1,(int)($_GET['cp']??1));
$food = $food_table_exists ? $conn->query("SELECT * FROM food_donations ORDER BY created_at DESC LIMIT $per OFFSET ".(($fp-1)*$per)) : false;
$cloth = $cloth_table_exists ? $conn->query("SELECT * FROM cloth_donations ORDER BY created_at DESC LIMIT $per OFFSET ".(($cp-1)*$per)) : false;
$food_rows = $food ? $food->fetch_all(MYSQLI_ASSOC) : [];
$cloth_rows = $cloth ? $cloth->fetch_all(MYSQLI_ASSOC) : [];
$food_pages  = (int)ceil($stats['food_total']/$per);
$cloth_pages = (int)ceil($stats['cloth_total']/$per);

// ── Users with search ─────────────────────────────────────────────────────
$ew = $search ? "AND (name LIKE '%".mysqli_real_escape_string($conn,$search)."%' OR email LIKE '%".mysqli_real_escape_string($conn,$search)."%')" : '';
$users    = $conn->query("SELECT * FROM register WHERE verified=1 $ew ORDER BY created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$sellers  = $conn->query("SELECT r.name,r.email,r.mobile,r.created_at,ss.store_name,ss.store_category,ss.is_verified,ss.village,ss.state FROM register r LEFT JOIN seller_stores ss ON ss.seller_email=r.email WHERE r.role='seller' AND r.verified=1 ORDER BY r.created_at DESC")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("SELECT p.*,ss.store_name FROM products p LEFT JOIN seller_stores ss ON ss.seller_email=p.seller_email ORDER BY p.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
$orders   = $conn->query("SELECT o.*,ss.store_name FROM orders o LEFT JOIN seller_stores ss ON ss.seller_email=o.seller_email ORDER BY o.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
$returns  = table_exists($conn, 'return_requests') ? $conn->query("SELECT rr.*,p.name AS product_name FROM return_requests rr JOIN products p ON p.id=rr.product_id ORDER BY rr.created_at DESC")->fetch_all(MYSQLI_ASSOC) : [];
$contacts = $conn->query("SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 30")->fetch_all(MYSQLI_ASSOC);

// ── Events & News ─────────────────────────────────────────────────────────
$events_news = table_exists($conn, 'events_news') ? $conn->query("SELECT * FROM events_news ORDER BY created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC) : [];
$events_count = count($events_news);
$stats['events_news'] = $events_count;

// ── Activity feed ─────────────────────────────────────────────────────────
$activity = [];
if ($food_table_exists || $cloth_table_exists || table_exists($conn, 'orders')) {
    $queries = [];
    if ($food_table_exists) {
        $queries[] = "SELECT 'food' AS atype, donor_email AS actor, status, created_at, id FROM food_donations";
    }
    if ($cloth_table_exists) {
        $queries[] = "SELECT 'cloth' AS atype, donor_email AS actor, status, created_at, id FROM cloth_donations";
    }
    if (table_exists($conn, 'orders')) {
        $queries[] = "SELECT 'order' AS atype, buyer_email AS actor, order_status AS status, created_at, id FROM orders";
    }
    if (!empty($queries)) {
        $activity = $conn->query(implode(" UNION ALL ", $queries) . " ORDER BY created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Donation status distribution ─────────────────────────────────────────
$don_statuses = [];
if ($food_table_exists || $cloth_table_exists) {
    $pieces = [];
    if ($food_table_exists) $pieces[] = "SELECT status FROM food_donations";
    if ($cloth_table_exists) $pieces[] = "SELECT status FROM cloth_donations";
    if (!empty($pieces)) {
        $don_statuses = $conn->query("SELECT status,COUNT(*) c FROM (" . implode(' UNION ALL ', $pieces) . ") x GROUP BY status ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
    }
}
$max_ds = max(1, array_sum(array_column($don_statuses,'c')));
$max_u  = max(1, $stats['total_users']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard | Adhaar – The SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/dashboard.css">
<style>
/* ─── Admin-specific styles ─── */
:root{ --accent:#006D77; --accent2:#2E8B57; --bg:#edf4f1; --card:#fff;
  --text:#102A43; --muted:#5A7184; --radius:16px; --shadow:0 4px 24px rgba(16,42,67,.09);
  --shadow-lg:0 12px 40px rgba(16,42,67,.15); --sidebar-w:250px; --topbar-h:60px; }
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }
body{ background:var(--bg); color:var(--text); min-height:100vh; }

/* Topbar */
.topbar{ display:flex; justify-content:space-between; align-items:center;
  margin-bottom:28px; flex-wrap:wrap; gap:12px; }
.topbar h2{ font-size:22px; font-weight:900; }
.topbar h2 small{ font-size:13px; font-weight:500; color:var(--muted); margin-left:8px; }
.admin-chip{ background:linear-gradient(135deg,var(--accent),var(--accent2));
  color:#fff; padding:6px 16px; border-radius:20px; font-size:11px; font-weight:700;
  letter-spacing:.5px; display:flex; align-items:center; gap:5px; }

/* KPI Grid */
.kpi-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:28px; }
.kpi{ background:var(--card); border-radius:var(--radius); padding:20px 22px;
  box-shadow:var(--shadow); border-left:4px solid var(--accent);
  transition:.3s; position:relative; overflow:hidden; cursor:default; }
.kpi:hover{ transform:translateY(-4px); box-shadow:var(--shadow-lg); }
.kpi-bg{ position:absolute; right:14px; top:50%; transform:translateY(-50%);
  font-size:2.6rem; opacity:.12; pointer-events:none; user-select:none; }
.kpi-label{ font-size:10px; font-weight:700; text-transform:uppercase;
  letter-spacing:.6px; color:var(--muted); margin-bottom:8px; }
.kpi-value{ font-size:32px; font-weight:900; line-height:1; margin-bottom:5px; }
.kpi-sub{ font-size:11px; color:var(--muted); font-weight:500; line-height:1.5; }
.kpi-badge{ display:inline-flex; align-items:center; gap:3px; font-size:10px;
  font-weight:700; padding:2px 8px; border-radius:10px; margin-top:6px; }
.kpi-badge.up{ background:#d1fae5; color:#065f46; }
.kpi-badge.warn{ background:#fef3c7; color:#92400e; }
.kpi.blue{ border-left-color:#3b82f6; } .kpi.blue .kpi-value{ color:#2563eb; }
.kpi.green{ border-left-color:#10b981; } .kpi.green .kpi-value{ color:#059669; }
.kpi.warn{ border-left-color:#f59e0b; } .kpi.warn .kpi-value{ color:#d97706; }
.kpi.purple{ border-left-color:#8b5cf6; } .kpi.purple .kpi-value{ color:#7c3aed; }
.kpi.olive .kpi-value{ color:var(--accent); }

/* Welcome band */
.welcome-band{ background:linear-gradient(135deg,#1e1d18 0%,var(--accent) 50%,var(--accent2) 100%);
  border-radius:var(--radius); padding:24px 28px; color:#fff; margin-bottom:28px;
  position:relative; overflow:hidden; display:flex;
  justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; }
.welcome-band::before{ content:''; position:absolute; right:-60px; top:-60px;
  width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,.06); pointer-events:none; }
.welcome-band::after{ content:''; position:absolute; right:80px; bottom:-80px;
  width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.04); pointer-events:none; }
.wb-text h3{ font-size:19px; font-weight:800; margin-bottom:4px; position:relative; }
.wb-text p{ font-size:13px; opacity:.85; position:relative; }
.wb-text .wb-date{ font-size:12px; opacity:.65; margin-top:3px; }
.wb-actions{ display:flex; gap:8px; flex-wrap:wrap; position:relative; }
.wb-btn{ padding:9px 18px; border-radius:10px; font-size:13px; font-weight:700;
  text-decoration:none; transition:.22s; white-space:nowrap; border:none; cursor:pointer; }
.wb-btn.white{ background:#fff; color:var(--accent); }
.wb-btn.white:hover{ background:#f0f0e0; transform:translateY(-2px); }
.wb-btn.outline{ background:rgba(255,255,255,.15); color:#fff; border:1.5px solid rgba(255,255,255,.35); }
.wb-btn.outline:hover{ background:rgba(255,255,255,.25); }

/* Overview 3-col */
.overview-row{ display:grid; grid-template-columns:1.2fr 1fr 1fr; gap:16px; margin-bottom:28px; }
.chart-card{ background:var(--card); border-radius:var(--radius); box-shadow:var(--shadow); padding:22px 24px; }
.chart-card h4{ font-size:14px; font-weight:800; margin-bottom:16px; padding-bottom:10px;
  border-bottom:2px solid #ede9df; display:flex; align-items:center; gap:7px; }
.bar-row{ display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.bar-label{ width:90px; font-size:12px; color:var(--muted); font-weight:600;
  flex-shrink:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.bar-track{ flex:1; height:10px; background:#f0ede5; border-radius:6px; overflow:hidden; }
.bar-fill{ height:100%; border-radius:6px; background:linear-gradient(90deg,var(--accent),var(--accent2));
  transition:width 1.3s cubic-bezier(.22,1,.36,1); }
.bar-val{ width:36px; text-align:right; font-size:12px; font-weight:700; color:var(--text); }

/* Activity feed */
.act-feed{ display:flex; flex-direction:column; }
.act-item{ display:flex; align-items:flex-start; gap:11px; padding:10px 0; border-bottom:1px solid #f0ede4; }
.act-item:last-child{ border-bottom:none; }
.act-dot{ width:32px; height:32px; border-radius:50%; display:flex; align-items:center;
  justify-content:center; font-size:13px; flex-shrink:0; }
.act-dot.food{ background:#fef3c7; }
.act-dot.cloth{ background:#dbeafe; }
.act-dot.order{ background:#d1fae5; }
.act-body{ flex:1; min-width:0; }
.act-body strong{ font-size:12px; font-weight:700; display:block;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.act-body span{ font-size:11px; color:var(--muted); }
.act-right{ text-align:right; flex-shrink:0; }
.act-time{ font-size:10px; color:var(--muted); white-space:nowrap; margin-bottom:3px; }

/* Quick actions */
.quick-grid{ display:grid; grid-template-columns:1fr 1fr; gap:9px; margin-bottom:16px; }
.quick-btn{ display:flex; align-items:center; gap:9px; padding:11px 13px;
  border-radius:11px; background:#f8f7f2; border:1.5px solid #ede9df;
  text-decoration:none; font-size:13px; font-weight:600; color:var(--text);
  transition:.22s; cursor:pointer; white-space:nowrap; }
.quick-btn:hover{ background:var(--accent); color:#fff; border-color:var(--accent); transform:translateY(-2px); }
.quick-btn .qi{ font-size:1rem; flex-shrink:0; }
.health-box{ background:#f8f7f2; border-radius:12px; padding:14px 16px; }
.health-box .hb-title{ font-size:10px; font-weight:700; color:var(--muted);
  text-transform:uppercase; letter-spacing:.6px; margin-bottom:10px; }
.hb-row{ display:flex; justify-content:space-between; font-size:12px;
  padding:5px 0; border-bottom:1px solid #ede9df; }
.hb-row:last-child{ border-bottom:none; }
.hb-row .k{ color:var(--muted); font-weight:600; }
.hb-row .v{ font-weight:800; }

/* Sec head */
.sec-head{ display:flex; justify-content:space-between; align-items:center;
  margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.sec-head h3{ font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px; }
.sec-count{ font-size:11px; color:var(--muted); font-weight:600;
  background:#f0ede5; padding:3px 10px; border-radius:20px; }
.sec-meta{ font-size:12px; color:var(--muted); }
.sec-meta strong{ font-weight:800; }

/* Search bar */
.s-bar{ display:flex; align-items:center; gap:0; }
.s-bar input{ padding:8px 13px; border:1.5px solid #e0ddd5; border-radius:10px 0 0 10px;
  font-size:13px; outline:none; background:#fafaf6; font-family:inherit;
  transition:.2s; min-width:180px; }
.s-bar input:focus{ border-color:var(--accent); background:#fff; }
.s-bar button{ padding:8px 14px; background:var(--accent); color:#fff; border:none;
  border-radius:0 10px 10px 0; cursor:pointer; font-size:14px; transition:.2s; }
.s-bar button:hover{ background:var(--accent2); }

/* Table */
.table-wrap{ background:var(--card); border-radius:var(--radius);
  box-shadow:var(--shadow); overflow:hidden; margin-bottom:20px; }
.table-scroll{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
.table-wrap table{ width:100%; border-collapse:collapse; min-width:500px; }
.table-wrap th{ background:#f6f5f0; padding:11px 14px; text-align:left;
  font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase;
  letter-spacing:.5px; border-bottom:2px solid #ede9df; white-space:nowrap; }
.table-wrap td{ padding:12px 14px; font-size:13px; border-bottom:1px solid #f0ede4;
  vertical-align:middle; }
.table-wrap tr:last-child td{ border-bottom:none; }
.table-wrap tbody tr{ transition:.18s; animation:rowIn .3s ease forwards; opacity:0; }
.table-wrap tbody tr:hover td{ background:#fafaf6; }
.table-wrap tbody tr:nth-child(1){ animation-delay:.03s; }
.table-wrap tbody tr:nth-child(2){ animation-delay:.07s; }
.table-wrap tbody tr:nth-child(3){ animation-delay:.11s; }
.table-wrap tbody tr:nth-child(n+4){ animation-delay:.14s; }
@keyframes rowIn{ from{ opacity:0; transform:translateY(4px); } to{ opacity:1; transform:none; } }
.table-wrap img{ width:48px; height:36px; object-fit:cover; border-radius:6px; border:1px solid #ede9df; }

/* User avatar */
.u-info{ display:flex; align-items:center; gap:10px; }
.u-avatar{ width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex; align-items:center; justify-content:center; color:#fff; font-size:13px; font-weight:800; flex-shrink:0; }
.u-name{ font-size:13px; font-weight:700; }
.u-email{ font-size:11px; color:var(--muted); }

/* Pills */
.pill{ display:inline-flex; align-items:center; gap:3px; padding:4px 10px;
  border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase;
  letter-spacing:.3px; white-space:nowrap; }
.pill.pending,.pill.placed{ background:#fef3c7; color:#92400e; }
.pill.accepted,.pill.confirmed,.pill.delivered,.pill.active,.pill.verified{ background:#d1fae5; color:#065f46; }
.pill.rejected,.pill.cancelled,.pill.returned,.pill.inactive{ background:#fee2e2; color:#991b1b; }
.pill.scheduled,.pill.shipped,.pill.donor{ background:#dbeafe; color:#1e40af; }
.pill.out_for_pickup,.pill.out_for_delivery,.pill.return_requested{ background:#fce7f3; color:#9d174d; }
.pill.picked_up,.pill.processing{ background:#ede9fe; color:#5b21b6; }
.pill.volunteer{ background:#d1fae5; color:#065f46; }
.pill.seller{ background:#fef3c7; color:#92400e; }
.pill.unverified{ background:#f3f4f6; color:#6b7280; }

/* Priority */
.pri{ display:inline-flex; align-items:center; gap:3px; padding:3px 8px;
  border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase; }
.pri.high{ background:#fee2e2; color:#991b1b; }
.pri.medium{ background:#fef3c7; color:#92400e; }
.pri.low{ background:#d1fae5; color:#065f46; }

/* Action buttons */
.btn{ padding:6px 12px; border:none; border-radius:8px; font-size:11px; font-weight:700;
  cursor:pointer; transition:.22s; margin:2px; display:inline-flex; align-items:center; gap:4px; }
.btn:hover{ transform:translateY(-1px); opacity:.9; }
.btn-accept{ background:#d1fae5; color:#065f46; }
.btn-accept:hover{ background:#a7f3d0; }
.btn-reject{ background:#fee2e2; color:#991b1b; }
.btn-reject:hover{ background:#fca5a5; }
.btn-schedule{ background:#ede9fe; color:#5b21b6; }
.btn-pickup{ background:#dbeafe; color:#1e40af; }
.btn-done{ background:#d1fae5; color:#065f46; }
.btn-verify{ background:#e0f2fe; color:#0369a1; }
.btn-deactivate{ background:#fef3c7; color:#92400e; }

/* Schedule inputs */
.schedule-inputs{ display:flex; flex-direction:column; gap:5px; margin-top:6px; }
.schedule-inputs input,.schedule-inputs select{ padding:6px 9px; border-radius:7px;
  border:1.5px solid #d1d5db; font-size:11px; background:#f9f9f6; outline:none; font-family:inherit; }
.schedule-inputs input:focus,.schedule-inputs select:focus{ border-color:var(--accent); }

/* Pagination */
.pagination{ display:flex; align-items:center; justify-content:center;
  gap:6px; padding:16px 0 4px; flex-wrap:wrap; }
.page-btn{ display:inline-flex; align-items:center; gap:4px; padding:7px 14px;
  border-radius:8px; border:1.5px solid #e0ddd5; background:#fff; color:var(--muted);
  font-size:13px; font-weight:600; text-decoration:none; cursor:pointer;
  transition:.22s; font-family:inherit; }
.page-btn:hover{ border-color:var(--accent); color:var(--accent); }
.page-btn.active{ background:var(--accent); color:#fff; border-color:var(--accent); }
.page-btn.disabled{ opacity:.4; cursor:not-allowed; pointer-events:none; }

/* Empty state */
.empty-box{ background:var(--card); border-radius:var(--radius); padding:44px 24px;
  text-align:center; box-shadow:var(--shadow); }
.empty-box .ei{ font-size:40px; margin-bottom:10px; }
.empty-box p{ color:var(--muted); font-size:13px; }

/* Sidebar logo */
.sidebar-logo{ color:#fff; font-size:17px; font-weight:800; padding-bottom:14px;
  border-bottom:1px solid rgba(255,255,255,.1); margin-bottom:6px; display:block; }
.sidebar-logo span{ display:block; font-size:10px; color:rgba(255,255,255,.4); font-weight:500; margin-top:2px; }

/* ── Responsive ── */
@media(max-width:1300px){ .kpi-grid{ grid-template-columns:repeat(3,1fr); } .overview-row{ grid-template-columns:1fr 1fr; } }
@media(max-width:1100px){ .kpi-grid{ grid-template-columns:1fr 1fr; } .overview-row{ grid-template-columns:1fr; } }
@media(max-width:600px){ .kpi-grid{ grid-template-columns:1fr 1fr; } }
</style>
</head>
<body>

<!-- Mobile topbar -->
<div class="mobile-topbar">
  <span class="m-logo"><img src="../assets/logo.png" alt="SoulServe" style="height:32px;object-fit:contain;vertical-align:middle"></span>
  <button class="hamburger" id="hamburger" aria-label="Open menu">
    <span></span><span></span><span></span>
  </button>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="app">
<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo"><img src="../assets/logo.png" alt="SoulServe" style="height:36px;object-fit:contain;display:block;margin-bottom:4px"><span>Management Panel</span></div>

  <button class="nav-item <?=$tab==='overview'?'active':''?>" onclick="sw('overview',this)">📊 Overview</button>

  <div class="nav-sec">Donations</div>
  <button class="nav-item <?=$tab==='donations'?'active':''?>" onclick="sw('donations',this)">
    🎁 All Donations<?php if($stats['donations_pending']>0):?><span class="nav-badge"><?=$stats['donations_pending']?></span><?php endif;?>
  </button>
  <button class="nav-item <?=$tab==='food'?'active':''?>" onclick="sw('food',this)">
    🍲 Food<?php if($stats['food_pending']>0):?><span class="nav-badge"><?=$stats['food_pending']?></span><?php endif;?>
  </button>
  <button class="nav-item <?=$tab==='cloth'?'active':''?>" onclick="sw('cloth',this)">
    👕 Clothes<?php if($stats['cloth_pending']>0):?><span class="nav-badge"><?=$stats['cloth_pending']?></span><?php endif;?>
  </button>
  <a href="distribution_system.php" class="nav-item">🚚 Distribution</a>

  <div class="nav-sec">Users</div>
  <button class="nav-item <?=$tab==='users'?'active':''?>" onclick="sw('users',this)">👥 All Users</button>
  <button class="nav-item <?=$tab==='sellers'?'active':''?>" onclick="sw('sellers',this)">🏪 Sellers</button>

  <div class="nav-sec">Shop</div>
  <button class="nav-item <?=$tab==='products'?'active':''?>" onclick="sw('products',this)">📦 Products</button>
  <button class="nav-item <?=$tab==='orders'?'active':''?>" onclick="sw('orders',this)">🛒 Orders</button>
  <button class="nav-item <?=$tab==='returns'?'active':''?>" onclick="sw('returns',this)">↩️ Returns</button>

  <div class="nav-sec">AI & Analytics</div>
  <button class="nav-item <?=$tab==='ai'?'active':''?>" onclick="sw('ai',this)">🤖 AI Insights</button>
  <button class="nav-item <?=$tab==='assign'?'active':''?>" onclick="sw('assign',this)">🎯 Task Assign</button>
  <button class="nav-item <?=$tab==='payout'?'active':''?>" onclick="sw('payout',this)">💰 Seller Payouts</button>

  <div class="nav-sec">Content</div>
  <button class="nav-item <?=$tab==='events'?'active':''?>" onclick="sw('events',this)">
    📰 Events &amp; News<?php if($events_count>0):?><span class="nav-badge green"><?=$events_count?></span><?php endif;?>
  </button>

  <div class="nav-sec">Support</div>
  <button class="nav-item <?=$tab==='contacts'?'active':''?>" onclick="sw('contacts',this)">
    📬 Messages<?php if($stats['contact_msgs']>0):?><span class="nav-badge green"><?=$stats['contact_msgs']?></span><?php endif;?>
  </button>

  <div class="sidebar-footer">
    <a href="../auth/logout.php" class="logout-link">⇦ Logout</a>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">
<div class="topbar">
  <div>
    <h2>Admin Dashboard <small id="adminTime"></small></h2>
  </div>
  <div class="admin-chip">🛡️ Admin · <?=date('d M Y')?></div>
</div>

<!-- ══════════════════ OVERVIEW TAB ══════════════════ -->
<div id="tab-overview" class="tab-panel <?=$tab==='overview'?'active':''?>">

  <!-- Welcome Band -->
  <div class="welcome-band">
    <div class="wb-text">
      <h3>Good day, Admin 👋</h3>
      <p>Full platform control at your fingertips — Adhaar The SoulServe</p>
      <div class="wb-date"><?=date('l, d F Y')?></div>
    </div>
    <div class="wb-actions">
      <button class="wb-btn white" onclick="sw('donations',document.querySelector('[onclick*=\'donations\']'))">🎁 All Donations</button>
      <button class="wb-btn white" onclick="sw('food',document.querySelector('[onclick*=\'food\']'))">🍲 Food Queue</button>
      <button class="wb-btn white" onclick="sw('cloth',document.querySelector('[onclick*=\'cloth\']'))">👕 Cloth Queue</button>
      <a href="distribution_system.php" class="wb-btn outline">🚚 Distribution</a>
    </div>
  </div>

  <!-- KPI Grid (8 cards, 4 per row) -->
  <div class="kpi-grid">
    <div class="kpi blue">
      <div class="kpi-bg">👥</div>
      <div class="kpi-label">Total Users</div>
      <div class="kpi-value"><?=$stats['total_users']?></div>
      <div class="kpi-sub"><?=$stats['donors']?> donors · <?=$stats['volunteers']?> vols · <?=$stats['sellers']?> sellers</div>
    </div>
    <div class="kpi warn">
      <div class="kpi-bg">⏳</div>
      <div class="kpi-label">Pending Review</div>
      <div class="kpi-value"><?=$stats['pending_don']?></div>
      <div class="kpi-sub">Donations awaiting action</div>
      <?php if($stats['pending_don']>0):?><span class="kpi-badge warn">⚠ Needs attention</span><?php endif;?>
    </div>
    <div class="kpi olive">
      <div class="kpi-bg">🎁</div>
      <div class="kpi-label">All Donations</div>
      <div class="kpi-value"><?=$stats['donations_total']?></div>
      <div class="kpi-sub"><?=$stats['donations_delivered']?> delivered · <?=$stats['donations_pending']?> pending</div>
      <?php if($stats['donations_pending']>0):?><span class="kpi-badge warn">⚠ Needs action</span><?php endif;?>
    </div>
    <div class="kpi olive">
      <div class="kpi-bg">🍱</div>
      <div class="kpi-label">Food Donations</div>
      <div class="kpi-value"><?=$stats['food_total']?></div>
      <div class="kpi-sub"><?=$stats['food_delivered']?> delivered · <?=$stats['food_pending']?> pending</div>
    </div>
    <div class="kpi olive">
      <div class="kpi-bg">👕</div>
      <div class="kpi-label">Cloth Donations</div>
      <div class="kpi-value"><?=$stats['cloth_total']?></div>
      <div class="kpi-sub"><?=$stats['cloth_delivered']?> delivered · <?=$stats['cloth_pending']?> pending</div>
    </div>
    <div class="kpi green">
      <div class="kpi-bg">📦</div>
      <div class="kpi-label">Active Products</div>
      <div class="kpi-value"><?=$stats['total_products']?></div>
      <div class="kpi-sub"><?=$stats['stores']?> active stores</div>
    </div>
    <div class="kpi blue">
      <div class="kpi-bg">🛒</div>
      <div class="kpi-label">Shop Orders</div>
      <div class="kpi-value"><?=$stats['total_orders']?></div>
      <div class="kpi-sub">₹<?=number_format($stats['revenue'],0)?> revenue</div>
      <span class="kpi-badge up">💰 Active revenue</span>
    </div>
    <div class="kpi purple">
      <div class="kpi-bg">🏪</div>
      <div class="kpi-label">Sellers</div>
      <div class="kpi-value"><?=$stats['sellers']?></div>
      <div class="kpi-sub"><?=$stats['stores']?> stores registered</div>
    </div>
    <div class="kpi">
      <div class="kpi-bg">📬</div>
      <div class="kpi-label">Messages</div>
      <div class="kpi-value"><?=$stats['contact_msgs']?></div>
      <div class="kpi-sub">Contact enquiries</div>
    </div>
    <div class="kpi" style="border-left-color:#10b981">
      <div class="kpi-bg">📰</div>
      <div class="kpi-label">Events &amp; News</div>
      <div class="kpi-value" style="color:#059669"><?=$stats['events_news']?></div>
      <div class="kpi-sub">Published posts</div>
    </div>
  </div>

  <!-- 3-Column Row: Charts / Activity / Quick Actions -->
  <div class="overview-row">

    <!-- Chart Card -->
    <div class="chart-card">
      <h4>📊 User Distribution</h4>
      <?php foreach([
        ['🎁 Donors',    $stats['donors'],    '#006D77'],
        ['🤝 Volunteers',$stats['volunteers'],'#3b82f6'],
        ['🏪 Sellers',   $stats['sellers'],   '#8b5cf6'],
      ] as [$lbl,$val,$col]): ?>
      <div class="bar-row">
        <div class="bar-label"><?=$lbl?></div>
        <div class="bar-track"><div class="bar-fill" data-w="<?=round($val/$max_u*100)?>%" style="background:<?=$col?>;width:0"></div></div>
        <div class="bar-val"><?=$val?></div>
      </div>
      <?php endforeach; ?>

      <div style="border-top:1px solid #ede9df;margin:16px 0 14px"></div>
      <h4 style="border:none;padding:0;margin-bottom:12px">📦 Donation Pipeline</h4>
      <?php foreach($don_statuses as $ds): ?>
      <div class="bar-row">
        <div class="bar-label"><?=ucfirst(str_replace('_',' ',$ds['status']))?></div>
        <div class="bar-track"><div class="bar-fill" data-w="<?=round($ds['c']/$max_ds*100)?>%" style="width:0"></div></div>
        <div class="bar-val"><?=$ds['c']?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Activity Feed -->
    <div class="chart-card">
      <h4>⚡ Recent Activity</h4>
      <div class="act-feed">
        <?php if(empty($activity)): ?>
          <div style="text-align:center;padding:28px;color:var(--muted);font-size:13px">No activity yet.</div>
        <?php else: ?>
          <?php foreach($activity as $act):
            $icons = ['food'=>'🍱','cloth'=>'👕','order'=>'🛒'];
            $labels = ['food'=>'Food donation','cloth'=>'Cloth donation','order'=>'Shop order'];
            $icon  = $icons[$act['atype']]  ?? '📌';
            $label = $labels[$act['atype']] ?? 'Action';
          ?>
          <div class="act-item">
            <div class="act-dot <?=htmlspecialchars($act['atype'])?>"><?=$icon?></div>
            <div class="act-body">
              <strong><?=$label?> #<?=(int)$act['id']?></strong>
              <span><?=htmlspecialchars(mb_substr($act['actor'],0,28))?></span>
            </div>
            <div class="act-right">
              <div class="act-time"><?=date('d M · h:i A',strtotime($act['created_at']))?></div>
              <span class="pill <?=htmlspecialchars($act['status'])?>"><?=ucfirst(str_replace('_',' ',$act['status']))?></span>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick Actions + Health -->
    <div class="chart-card">
      <h4>🚀 Quick Actions</h4>
      <div class="quick-grid">
        <button class="quick-btn" onclick="sw('donations',document.querySelector('[onclick*=\'donations\']'))"><span class="qi">🎁</span>Donations</button>
        <button class="quick-btn" onclick="sw('food',document.querySelector('[onclick*=\'food\']'))"><span class="qi">🍲</span>Food</button>
        <button class="quick-btn" onclick="sw('cloth',document.querySelector('[onclick*=\'cloth\']'))"><span class="qi">👕</span>Cloth</button>
        <a href="distribution_system.php" class="quick-btn"><span class="qi">🚚</span>Distribute</a>
        <button class="quick-btn" onclick="sw('users',document.querySelector('[onclick*=\'users\']'))"><span class="qi">👥</span>Users</button>
        <button class="quick-btn" onclick="sw('sellers',document.querySelector('[onclick*=\'sellers\']'))"><span class="qi">🏪</span>Sellers</button>
        <button class="quick-btn" onclick="sw('orders',document.querySelector('[onclick*=\'orders\']'))"><span class="qi">🛒</span>Orders</button>
        <button class="quick-btn" onclick="sw('contacts',document.querySelector('[onclick*=\'contacts\']'))"><span class="qi">📬</span>Messages</button>
        <a href="../auth/logout.php" class="quick-btn" style="color:#991b1b"><span class="qi">⇦</span>Logout</a>
      </div>
      <div class="health-box" style="margin-top:16px">
        <div class="hb-title">Platform Health</div>
        <?php
header('Content-Type: text/html; charset=utf-8');
        $total_don = $stats['food_total'] + $stats['cloth_total'];
        $total_del = $stats['food_delivered'] + $stats['cloth_delivered'];
        $del_rate  = $total_don > 0 ? round($total_del/$total_don*100) : 0;
        foreach([
          ['Total Donations', $total_don,          '#006D77'],
          ['Delivery Rate',   $del_rate.'%',        '#059669'],
          ['Shop Revenue',    '₹'.number_format($stats['revenue'],0), '#3b82f6'],
          ['Active Sellers',  $stats['sellers'],    '#8b5cf6'],
        ] as [$k,$v,$c]): ?>
        <div class="hb-row">
          <span class="k"><?=$k?></span>
          <span class="v" style="color:<?=$c?>"><?=$v?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>


<!-- ══════════════════ ALL DONATIONS TAB (Unified) ══════════════════ -->
<div id="tab-donations" class="tab-panel <?=$tab==='donations'?'active':''?>">
  <div class="sec-head" style="flex-wrap:wrap;gap:12px">
    <h3>🎁 All Donations <span class="sec-count"><?=$stats['donations_total']?> total</span></h3>
    <span class="sec-meta">
      Pending: <strong style="color:#d97706"><?=$stats['donations_pending']?></strong> &nbsp;·&nbsp;
      Delivered: <strong style="color:#059669"><?=$stats['donations_delivered']?></strong>
    </span>
    <!-- Filters -->
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-left:auto">
      <input type="hidden" name="tab" value="donations">
      <select name="don_cat" onchange="this.form.submit()" style="padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;background:#fff;cursor:pointer">
        <option value="all" <?=$don_cat_filter==='all'?'selected':''?>>All Categories</option>
        <?php foreach($don_cat_icons as $key=>$icon): ?>
        <option value="<?=$key?>" <?=$don_cat_filter===$key?'selected':''?>><?=$icon?> <?=ucfirst(str_replace('_',' ',$key))?></option>
        <?php endforeach; ?>
      </select>
      <select name="don_status" onchange="this.form.submit()" style="padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;background:#fff;cursor:pointer">
        <option value="all" <?=$don_status_filter==='all'?'selected':''?>>All Statuses</option>
        <?php foreach(['pending','accepted','scheduled','out_for_pickup','picked_up','delivered','rejected'] as $st): ?>
        <option value="<?=$st?>" <?=$don_status_filter===$st?'selected':''?>><?=ucfirst(str_replace('_',' ',$st))?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if(empty($donations_rows)): ?>
  <div style="text-align:center;padding:48px 24px;color:var(--muted)">
    <div style="font-size:48px;margin-bottom:14px">📭</div>
    <p>No donations found<?=$don_cat_filter!=='all'?' for '.ucfirst(str_replace('_',' ',$don_cat_filter)):''?>.</p>
  </div>
  <?php else: ?>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead>
      <tr>
        <th>ID</th><th>Category</th><th>Donor</th><th>Qty</th><th>Description</th>
        <th>Pickup Address</th><th>Contact</th><th>Priority</th><th>Photo</th><th>Status</th><th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($donations_rows as $d):
      $cat_icon = $don_cat_icons[$d['category']] ?? '📦';
      $pri = $d['priority'] ?? 'medium';
      $pri_icons = ['high'=>'🔴','medium'=>'🟡','low'=>'🟢'];
    ?>
    <tr>
      <td style="font-weight:700;color:var(--muted);font-size:11px;white-space:nowrap">
        <?=htmlspecialchars($d['donation_id'] ?? 'DON-'.str_pad($d['id'],6,'0',STR_PAD_LEFT))?>
      </td>
      <td><span style="background:#f0ede5;padding:3px 9px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap">
        <?=$cat_icon?> <?=ucfirst(str_replace('_',' ',$d['category']))?>
      </span></td>
      <td><div style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($d['donor_email'])?></div></td>
      <td><strong><?=htmlspecialchars($d['quantity']??'—')?></strong></td>
      <td><div style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars(mb_substr($d['description']??'—',0,50))?></div></td>
      <td><div style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($d['pickup_address']??'—')?></div></td>
      <td style="font-size:12px"><?=htmlspecialchars($d['contact']??'—')?></td>
      <td><span class="pri <?=$pri?>"><?=$pri_icons[$pri]??'🟡'?> <?=ucfirst($pri)?></span></td>
      <td><?php if(!empty($d['image'])): ?><img src="<?=htmlspecialchars(image_url($d['image']))?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px"><?php else: ?>—<?php endif; ?></td>
      <td><span class="pill <?=htmlspecialchars($d['status'])?>"><?=ucfirst(str_replace('_',' ',$d['status']))?></span></td>
      <td style="min-width:160px">
        <?php if($d['status']==='pending'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php">
            <?=csrf_field()?>
            <input type="hidden" name="id"    value="<?=(int)$d['id']?>">
            <input type="hidden" name="table" value="donations">
            <input type="hidden" name="status" value="accepted">
            <button class="btn btn-accept">✓ Accept</button>
          </form>
          <form style="display:inline" method="POST" action="../api/update_status.php">
            <?=csrf_field()?>
            <input type="hidden" name="id"    value="<?=(int)$d['id']?>">
            <input type="hidden" name="table" value="donations">
            <input type="hidden" name="status" value="rejected">
            <button class="btn btn-reject">✗ Reject</button>
          </form>
        <?php elseif($d['status']==='accepted'): ?>
          <form method="POST" action="../api/update_status.php">
            <?=csrf_field()?>
            <input type="hidden" name="id"    value="<?=(int)$d['id']?>">
            <input type="hidden" name="table" value="donations">
            <input type="hidden" name="status" value="scheduled">
            <div class="schedule-inputs">
              <input type="date"  name="pickup_date" required>
              <input type="text"  name="pickup_time" placeholder="e.g. 10 AM – 12 PM" required>
              <input type="email" name="volunteer_email" placeholder="Volunteer email" required>
              <button class="btn btn-schedule">📅 Schedule</button>
            </div>
          </form>
        <?php elseif($d['status']==='scheduled'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php">
            <?=csrf_field()?>
            <input type="hidden" name="id"    value="<?=(int)$d['id']?>">
            <input type="hidden" name="table" value="donations">
            <input type="hidden" name="status" value="out_for_pickup">
            <button class="btn btn-pickup">🚚 Out for Pickup</button>
          </form>
        <?php elseif($d['status']==='out_for_pickup'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php">
            <?=csrf_field()?>
            <input type="hidden" name="id"    value="<?=(int)$d['id']?>">
            <input type="hidden" name="table" value="donations">
            <input type="hidden" name="status" value="picked_up">
            <button class="btn btn-done">📦 Mark Picked</button>
          </form>
        <?php elseif($d['status']==='picked_up'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php">
            <?=csrf_field()?>
            <input type="hidden" name="id"    value="<?=(int)$d['id']?>">
            <input type="hidden" name="table" value="donations">
            <input type="hidden" name="status" value="delivered">
            <button class="btn btn-accept">✅ Delivered</button>
          </form>
        <?php else: ?>
          <span style="color:var(--muted);font-size:12px">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>

  <?php if($donations_pages>1): ?>
  <div class="pagination">
    <a href="?tab=donations&dp=<?=max(1,$dp-1)?>&don_cat=<?=$don_cat_filter?>&don_status=<?=$don_status_filter?>" class="page-btn <?=$dp<=1?'disabled':''?>">← Prev</a>
    <?php for($pg=max(1,$dp-2);$pg<=min($donations_pages,$dp+2);$pg++): ?>
    <a href="?tab=donations&dp=<?=$pg?>&don_cat=<?=$don_cat_filter?>&don_status=<?=$don_status_filter?>" class="page-btn <?=$pg===$dp?'active':''?>"><?=$pg?></a>
    <?php endfor; ?>
    <a href="?tab=donations&dp=<?=min($donations_pages,$dp+1)?>&don_cat=<?=$don_cat_filter?>&don_status=<?=$don_status_filter?>" class="page-btn <?=$dp>=$donations_pages?'disabled':''?>">Next →</a>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ══════════════════ FOOD DONATIONS TAB ══════════════════ -->
<div id="tab-food" class="tab-panel <?=$tab==='food'?'active':''?>">
  <div class="sec-head">
    <h3>🍲 Food Donations <span class="sec-count"><?=$stats['food_total']?> total</span></h3>
    <span class="sec-meta">
      Pending: <strong style="color:#d97706"><?=$stats['food_pending']?></strong> &nbsp;·&nbsp;
      Delivered: <strong style="color:#059669"><?=$stats['food_delivered']?></strong>
    </span>
  </div>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead>
      <tr><th>#</th><th>Donor</th><th>Prepared</th><th>Qty</th><th>Priority</th><th>Pickup Address</th><th>Contact</th><th>Photo</th><th>Status</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php while($f=$food->fetch_assoc()):
      $pri = $f['priority'] ?? 'medium';
      $pri_icons = ['high'=>'🔴','medium'=>'🟡','low'=>'🟢'];
      $pi = $pri_icons[$pri] ?? '🟡';
    ?>
    <tr>
      <td style="font-weight:700;color:var(--muted)"><?=(int)$f['id']?></td>
      <td><div style="font-size:11px;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($f['donor_email'])?></div></td>
      <td style="font-size:11px;white-space:nowrap"><?=htmlspecialchars($f['food_time']??'N/A')?></td>
      <td><strong><?=(int)$f['quantity']?></strong><span style="font-size:11px;color:var(--muted);margin-left:4px">(<?=(int)$f['safe_hours']?>h safe)</span></td>
      <td><span class="pri <?=$pri?>"><?=$pi?> <?=ucfirst($pri)?></span></td>
      <td><div style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px"><?=htmlspecialchars($f['pickup_address'])?></div></td>
      <td style="font-size:12px"><?=htmlspecialchars($f['contact'])?></td>
      <td><?php if(!empty($f['image'])): ?><img src="<?=htmlspecialchars(image_url($f['image']))?>" alt=""><?php else: ?><span style="color:var(--muted);font-size:11px">—</span><?php endif; ?></td>
      <td><span class="pill <?=htmlspecialchars($f['status'])?>"><?=ucfirst(str_replace('_',' ',$f['status']))?></span></td>
      <td style="min-width:140px">
        <?php if($f['status']==='pending'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="table" value="food_donations"><input type="hidden" name="status" value="accepted"><button class="btn btn-accept">✓ Accept</button></form>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="table" value="food_donations"><input type="hidden" name="status" value="rejected"><button class="btn btn-reject">✗ Reject</button></form>
        <?php elseif($f['status']==='accepted'): ?>
          <form method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="table" value="food_donations"><input type="hidden" name="status" value="scheduled">
          <div class="schedule-inputs">
            <input type="date" name="pickup_date" required>
            <input type="text" name="pickup_time" placeholder="e.g. 10 AM – 12 PM" required>
            <input type="email" name="volunteer_email" placeholder="Volunteer email" required>
            <button class="btn btn-schedule">📅 Schedule</button>
          </div></form>
        <?php elseif($f['status']==='scheduled'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="table" value="food_donations"><input type="hidden" name="status" value="out_for_pickup"><button class="btn btn-pickup">🚚 Out for Pickup</button></form>
        <?php elseif($f['status']==='out_for_pickup'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="table" value="food_donations"><input type="hidden" name="status" value="picked_up"><button class="btn btn-done">📦 Mark Picked</button></form>
        <?php elseif($f['status']==='picked_up'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="table" value="food_donations"><input type="hidden" name="status" value="delivered"><button class="btn btn-accept">✅ Delivered</button></form>
        <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div></div>
  <?php if($food_pages>1): ?>
  <div class="pagination">
    <a href="?tab=food&fp=<?=max(1,$fp-1)?>" class="page-btn <?=$fp<=1?'disabled':''?>">← Prev</a>
    <?php for($p=max(1,$fp-2);$p<=min($food_pages,$fp+2);$p++): ?>
    <a href="?tab=food&fp=<?=$p?>" class="page-btn <?=$p===$fp?'active':''?>"><?=$p?></a>
    <?php endfor; ?>
    <a href="?tab=food&fp=<?=min($food_pages,$fp+1)?>" class="page-btn <?=$fp>=$food_pages?'disabled':''?>">Next →</a>
  </div>
  <?php endif; ?>
</div>

<!-- ══════════════════ CLOTH DONATIONS TAB ══════════════════ -->
<div id="tab-cloth" class="tab-panel <?=$tab==='cloth'?'active':''?>">
  <div class="sec-head">
    <h3>👕 Cloth Donations <span class="sec-count"><?=$stats['cloth_total']?> total</span></h3>
    <span class="sec-meta">Pending: <strong style="color:#d97706"><?=$stats['cloth_pending']?></strong> &nbsp;·&nbsp; Delivered: <strong style="color:#059669"><?=$stats['cloth_delivered']?></strong></span>
  </div>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead><tr><th>#</th><th>Donor</th><th>Type</th><th>Qty</th><th>Condition</th><th>Pickup Address</th><th>Contact</th><th>Photo</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php while($c=$cloth->fetch_assoc()): ?>
    <tr>
      <td style="font-weight:700;color:var(--muted)"><?=(int)$c['id']?></td>
      <td><div style="font-size:11px;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($c['donor_email'])?></div></td>
      <td><strong style="font-size:12px"><?=htmlspecialchars($c['cloth_type'])?></strong></td>
      <td><strong><?=(int)$c['quantity']?></strong></td>
      <td><span style="font-size:11px;background:#f0ede5;padding:2px 9px;border-radius:6px;font-weight:600"><?=ucfirst($c['condition_type']??'good')?></span></td>
      <td><div style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px"><?=htmlspecialchars($c['pickup_address'])?></div></td>
      <td style="font-size:12px"><?=htmlspecialchars($c['contact'])?></td>
      <td><?php if(!empty($c['image'])): ?><img src="<?=htmlspecialchars(image_url($c['image']))?>" alt=""><?php else: ?><span style="color:var(--muted);font-size:11px">—</span><?php endif; ?></td>
      <td><span class="pill <?=htmlspecialchars($c['status'])?>"><?=ucfirst(str_replace('_',' ',$c['status']))?></span></td>
      <td style="min-width:140px">
        <?php if($c['status']==='pending'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="table" value="cloth_donations"><input type="hidden" name="status" value="accepted"><button class="btn btn-accept">✓ Accept</button></form>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="table" value="cloth_donations"><input type="hidden" name="status" value="rejected"><button class="btn btn-reject">✗ Reject</button></form>
        <?php elseif($c['status']==='accepted'): ?>
          <form method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="table" value="cloth_donations"><input type="hidden" name="status" value="scheduled">
          <div class="schedule-inputs">
            <input type="date" name="pickup_date" required>
            <input type="text" name="pickup_time" placeholder="e.g. 10 AM – 12 PM" required>
            <input type="email" name="volunteer_email" placeholder="Volunteer email" required>
            <button class="btn btn-schedule">📅 Schedule</button>
          </div></form>
        <?php elseif($c['status']==='scheduled'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="table" value="cloth_donations"><input type="hidden" name="status" value="out_for_pickup"><button class="btn btn-pickup">🚚 Out for Pickup</button></form>
        <?php elseif($c['status']==='out_for_pickup'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="table" value="cloth_donations"><input type="hidden" name="status" value="picked_up"><button class="btn btn-done">📦 Mark Picked</button></form>
        <?php elseif($c['status']==='picked_up'): ?>
          <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="table" value="cloth_donations"><input type="hidden" name="status" value="delivered"><button class="btn btn-accept">✅ Delivered</button></form>
        <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div></div>
  <?php if($cloth_pages>1): ?>
  <div class="pagination">
    <a href="?tab=cloth&cp=<?=max(1,$cp-1)?>" class="page-btn <?=$cp<=1?'disabled':''?>">← Prev</a>
    <?php for($p=max(1,$cp-2);$p<=min($cloth_pages,$cp+2);$p++): ?>
    <a href="?tab=cloth&cp=<?=$p?>" class="page-btn <?=$p===$cp?'active':''?>"><?=$p?></a>
    <?php endfor; ?>
    <a href="?tab=cloth&cp=<?=min($cloth_pages,$cp+1)?>" class="page-btn <?=$cp>=$cloth_pages?'disabled':''?>">Next →</a>
  </div>
  <?php endif; ?>
</div>

<!-- ══════════════════ ALL USERS TAB ══════════════════ -->
<div id="tab-users" class="tab-panel <?=$tab==='users'?'active':''?>">
  <div class="sec-head">
    <h3>👥 All Users <span class="sec-count"><?=$stats['total_users']?> verified</span></h3>
    <form method="GET" class="s-bar">
      <input type="hidden" name="tab" value="users">
      <input type="text" name="s" placeholder="Search name or email…" value="<?=htmlspecialchars($search)?>">
      <button type="submit">🔍</button>
    </form>
  </div>
  <?php if($search): ?><p style="margin-bottom:12px;font-size:13px;color:var(--muted)">Results for: <strong><?=htmlspecialchars($search)?></strong> <a href="?tab=users" style="color:var(--accent);font-weight:700;margin-left:8px">Clear ✕</a></p><?php endif; ?>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead><tr><th>#</th><th>User</th><th>Mobile</th><th>Role</th><th>Joined</th></tr></thead>
    <tbody>
    <?php foreach($users as $i=>$u): ?>
    <tr>
      <td style="color:var(--muted);font-weight:700"><?=$i+1?></td>
      <td>
        <div class="u-info">
          <div class="u-avatar"><?=strtoupper(mb_substr($u['name'],0,1))?></div>
          <div><div class="u-name"><?=htmlspecialchars($u['name'])?></div><div class="u-email"><?=htmlspecialchars($u['email'])?></div></div>
        </div>
      </td>
      <td style="font-size:13px"><?=htmlspecialchars($u['mobile'])?></td>
      <td><span class="pill <?=$u['role']?>"><?=ucfirst($u['role'])?></span></td>
      <td style="font-size:12px;white-space:nowrap"><?=date('d M Y',strtotime($u['created_at']))?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($users)): ?><tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted)">No users found<?=$search?' for "'.htmlspecialchars($search).'"':''?>.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- ══════════════════ SELLERS TAB ══════════════════ -->
<div id="tab-sellers" class="tab-panel <?=$tab==='sellers'?'active':''?>">
  <div class="sec-head"><h3>🏪 Sellers <span class="sec-count"><?=count($sellers)?></span></h3></div>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead><tr><th>#</th><th>Seller</th><th>Store</th><th>Category</th><th>Location</th><th>Verified</th><th>Joined</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($sellers as $i=>$s): ?>
    <tr>
      <td style="color:var(--muted);font-weight:700"><?=$i+1?></td>
      <td>
        <div class="u-info">
          <div class="u-avatar" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"><?=strtoupper(mb_substr($s['name'],0,1))?></div>
          <div><div class="u-name"><?=htmlspecialchars($s['name'])?></div><div class="u-email"><?=htmlspecialchars($s['email'])?></div></div>
        </div>
      </td>
      <td style="font-weight:600"><?=htmlspecialchars($s['store_name']??'—')?></td>
      <td><span style="font-size:11px;background:#f0ede5;padding:2px 9px;border-radius:6px;font-weight:600"><?=ucfirst(str_replace('_',' ',$s['store_category']??''))?></span></td>
      <td style="font-size:12px"><?=htmlspecialchars(($s['village']?$s['village'].', ':'').($s['state']??''))?></td>
      <td><span class="pill <?=$s['is_verified']?'accepted':'unverified'?>"><?=$s['is_verified']?'✓ Verified':'Pending'?></span></td>
      <td style="font-size:12px;white-space:nowrap"><?=date('d M Y',strtotime($s['created_at']))?></td>
      <td>
        <?php if(!$s['is_verified']&&$s['store_name']): ?>
        <form style="display:inline" method="POST" action="../api/update_status.php"><?=csrf_field()?><input type="hidden" name="action" value="verify_seller"><input type="hidden" name="email" value="<?=htmlspecialchars($s['email'])?>"><button class="btn btn-verify">✓ Verify</button></form>
        <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</div>

<!-- ══════════════════ PRODUCTS TAB ══════════════════ -->
<div id="tab-products" class="tab-panel <?=$tab==='products'?'active':''?>">
  <div class="sec-head"><h3>📦 Products <span class="sec-count"><?=$stats['total_products']?> active</span></h3></div>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead><tr><th>#</th><th>Photo</th><th>Name</th><th>Store</th><th>Category</th><th>Price</th><th>Stock</th><th>Sold</th><th>Rating</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($products as $p): ?>
    <tr>
      <td style="color:var(--muted);font-weight:700"><?=(int)$p['id']?></td>
      <td><?php if(!empty($p['image1'])): ?><img src="<?=htmlspecialchars(image_url($p['image1']))?>" alt=""><?php else: ?><div style="width:48px;height:36px;background:#f0ede5;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:16px">🛍️</div><?php endif; ?></td>
      <td><strong style="font-size:13px"><?=htmlspecialchars($p['name'])?></strong></td>
      <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($p['store_name']??'—')?></td>
      <td style="font-size:12px"><?=ucfirst(str_replace('_',' ',$p['category']))?></td>
      <td><strong>₹<?=number_format($p['price'],0)?></strong></td>
      <td style="font-weight:700"><?=(int)$p['stock']?></td>
      <td style="color:var(--accent);font-weight:700"><?=(int)$p['total_sold']?></td>
      <td>⭐ <?=number_format($p['avg_rating'],1)?></td>
      <td><span class="pill <?=$p['is_active']?'accepted':'rejected'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
      <td>
        <form style="display:inline" method="POST" action="../api/add_product.php"><?=csrf_field()?><input type="hidden" name="toggle_id" value="<?=(int)$p['id']?>"><input type="hidden" name="active" value="<?=$p['is_active']?0:1?>"><button class="btn <?=$p['is_active']?'btn-reject btn-deactivate':'btn-accept'?>"><?=$p['is_active']?'Deactivate':'Activate'?></button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</div>

<!-- ══════════════════ ORDERS TAB ══════════════════ -->
<div id="tab-orders" class="tab-panel <?=$tab==='orders'?'active':''?>">
  <div class="sec-head">
    <h3>🛒 Orders <span class="sec-count"><?=$stats['total_orders']?> total</span></h3>
    <span style="font-size:13px;font-weight:700;color:var(--accent)">Revenue: ₹<?=number_format($stats['revenue'],0)?></span>
  </div>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead><tr><th>Order #</th><th>Buyer</th><th>Store</th><th>Amount</th><th>Payment</th><th>City</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($orders as $o): ?>
    <tr>
      <td><strong style="font-size:12px"><?=htmlspecialchars($o['order_number'])?></strong></td>
      <td style="font-size:11px;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($o['buyer_email'])?></td>
      <td style="font-size:12px"><?=htmlspecialchars($o['store_name']??'—')?></td>
      <td><strong>₹<?=number_format($o['total_amount'],2)?></strong></td>
      <td><span style="font-size:11px;background:#f0ede5;padding:2px 9px;border-radius:6px;font-weight:700"><?=strtoupper($o['payment_method'])?></span></td>
      <td style="font-size:12px"><?=htmlspecialchars($o['shipping_city'])?></td>
      <td><span class="pill <?=htmlspecialchars($o['order_status'])?>"><?=ucfirst(str_replace('_',' ',$o['order_status']))?></span></td>
      <td style="font-size:11px;white-space:nowrap"><?=date('d M Y',strtotime($o['created_at']))?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</div>

<!-- ══════════════════ RETURNS TAB ══════════════════ -->
<div id="tab-returns" class="tab-panel <?=$tab==='returns'?'active':''?>">
  <div class="sec-head"><h3>↩️ Return Requests</h3></div>
  <?php if(empty($returns)): ?>
  <div class="empty-box"><div class="ei">✅</div><p>No return requests yet.</p></div>
  <?php else: ?>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead><tr><th>#</th><th>Order</th><th>Product</th><th>Buyer</th><th>Reason</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($returns as $r): ?>
    <tr>
      <td style="font-weight:700;color:var(--muted)"><?=(int)$r['id']?></td>
      <td style="font-weight:700">#<?=(int)$r['order_id']?></td>
      <td style="font-size:12px;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($r['product_name'])?></td>
      <td style="font-size:11px;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($r['buyer_email'])?></td>
      <td style="font-size:12px"><?=ucfirst(str_replace('_',' ',$r['reason']))?></td>
      <td><span class="pill <?=$r['status']==='requested'?'pending':$r['status']?>"><?=ucfirst(str_replace('_',' ',$r['status']))?></span></td>
      <td style="font-size:11px;white-space:nowrap"><?=date('d M Y',strtotime($r['created_at']))?></td>
      <td>
        <?php if($r['status']==='requested'): ?>
        <form style="display:inline" method="POST" action="../api/update_order_status.php"><?=csrf_field()?><input type="hidden" name="return_id" value="<?=(int)$r['id']?>"><input type="hidden" name="return_status" value="approved"><button class="btn btn-accept">✓ Approve</button></form>
        <form style="display:inline" method="POST" action="../api/update_order_status.php"><?=csrf_field()?><input type="hidden" name="return_id" value="<?=(int)$r['id']?>"><input type="hidden" name="return_status" value="rejected"><button class="btn btn-reject">✗ Reject</button></form>
        <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
  <?php endif; ?>
</div>

<!-- ══════════════════ EVENTS & NEWS TAB ══════════════════ -->
<div id="tab-events" class="tab-panel <?=$tab==='events'?'active':''?>">
<div class="sec-head">
  <h3>📰 Events &amp; News <span class="sec-count"><?=$events_count?> posts</span></h3>
  <button class="btn btn-accept" onclick="document.getElementById('evtCreateModal').style.display='flex'" style="padding:9px 18px;font-size:13px">+ New Post</button>
</div>

<?php
header('Content-Type: text/html; charset=utf-8');
$msg_map = ['created'=>'✅ Post created!','updated'=>'✅ Post updated!','deleted'=>'🗑️ Post deleted.','toggled'=>'🔄 Visibility toggled.','missing_fields'=>'⚠️ Title and content are required.'];
$emsg = $_GET['msg'] ?? '';
if ($emsg && $tab === 'events' && isset($msg_map[$emsg])): ?>
<div style="background:<?=$emsg==='missing_fields'?'#fef3c7':'#d1fae5'?>;border-radius:10px;padding:12px 18px;margin-bottom:16px;font-size:13px;font-weight:700;color:<?=$emsg==='missing_fields'?'#92400e':'#065f46'?>"><?=$msg_map[$emsg]?></div>
<?php endif; ?>

<?php if(empty($events_news)): ?>
<div class="empty-box"><div class="ei">📰</div><p>No events or news yet. Click <strong>+ New Post</strong> to create the first one.</p></div>
<?php else: ?>
<div class="table-wrap"><div class="table-scroll"><table>
  <thead>
    <tr><th>Emoji</th><th>Title</th><th>Category</th><th>Date</th><th>Status</th><th>Created By</th><th>Image</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach($events_news as $ev): 
    $cat_colors = ['event'=>'#dbeafe','news'=>'#d1fae5','drive'=>'#fce7f3','milestone'=>'#ede9fe'];
    $cat_text   = ['event'=>'#1e40af','news'=>'#065f46','drive'=>'#9d174d','milestone'=>'#5b21b6'];
    $cc = $cat_colors[$ev['category']] ?? '#f0ede5';
    $ct = $cat_text[$ev['category']]   ?? '#5a594d';
  ?>
  <tr>
    <td style="font-size:1.6rem;text-align:center"><?=htmlspecialchars($ev['emoji']??'📰')?></td>
    <td>
      <strong style="font-size:13px"><?=htmlspecialchars($ev['title'])?></strong>
      <div style="font-size:11px;color:var(--muted);margin-top:3px;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars(mb_substr($ev['content'],0,80)).(mb_strlen($ev['content'])>80?'…':'')?></div>
    </td>
    <td><span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:<?=$cc?>;color:<?=$ct?>"><?=ucfirst($ev['category'])?></span></td>
    <td style="font-size:12px;white-space:nowrap"><?=$ev['event_date'] ? date('d M Y', strtotime($ev['event_date'])) : '—'?></td>
    <td>
      <form method="POST" action="../api/admin_events.php" style="display:inline">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?=(int)$ev['id']?>">
        <button type="submit" class="pill <?=$ev['is_published']?'accepted':'unverified'?>" style="cursor:pointer;border:none;font-family:inherit">
          <?=$ev['is_published']?'✓ Published':'○ Draft'?>
        </button>
      </form>
    </td>
    <td style="font-size:11px;color:var(--muted)"><?=htmlspecialchars(mb_substr($ev['created_by'],0,24))?></td>
    <td>
      <?php if(!empty($ev['image'])): ?>
        <img src="<?=htmlspecialchars(image_url($ev['image']))?>" alt="" style="width:54px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #ede9df">
      <?php else: ?>
        <span style="font-size:11px;color:var(--muted)">—</span>
      <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
      <button class="btn btn-schedule" onclick="openEditModal(<?=htmlspecialchars(json_encode($ev))?>)">✏️ Edit</button>
      <form method="POST" action="../api/admin_events.php" style="display:inline" onsubmit="return confirm('Delete this post?')">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?=(int)$ev['id']?>">
        <button type="submit" class="btn btn-reject">🗑️ Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>

<!-- ── CREATE MODAL ── -->
<div id="evtCreateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:20px;padding:32px;max-width:580px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
      <h3 style="font-size:18px;font-weight:800">📰 Create Event / News Post</h3>
      <button onclick="document.getElementById('evtCreateModal').style.display='none'" style="background:#fee2e2;border:none;border-radius:8px;padding:6px 12px;cursor:pointer;font-weight:700;color:#991b1b;font-size:13px">✕ Close</button>
    </div>
    <form method="POST" action="../api/admin_events.php" enctype="multipart/form-data">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="create">
      <div style="display:grid;gap:14px">
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">TITLE *</label>
          <input type="text" name="title" required placeholder="Post title…" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:14px;font-family:inherit;outline:none;transition:.2s" onfocus="this.style.borderColor='#7a7d3f'" onblur="this.style.borderColor='#e0ddd5'">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">CONTENT *</label>
          <textarea name="content" required rows="4" placeholder="Write the full post content…" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:14px;font-family:inherit;outline:none;resize:vertical;transition:.2s" onfocus="this.style.borderColor='#7a7d3f'" onblur="this.style.borderColor='#e0ddd5'"></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">CATEGORY</label>
            <select name="category" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:13px;font-family:inherit;outline:none;background:#fff">
              <option value="news">📰 News</option>
              <option value="event">🎉 Event</option>
              <option value="drive">💚 Drive</option>
              <option value="milestone">🏆 Milestone</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">EMOJI</label>
            <input type="text" name="emoji" value="📰" maxlength="10" placeholder="📰" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:14px;font-family:inherit;outline:none;text-align:center;font-size:1.4rem">
          </div>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">EVENT DATE (optional)</label>
          <input type="date" name="event_date" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:13px;font-family:inherit;outline:none">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">IMAGE (optional)</label>
          <input type="file" name="image" accept="image/*" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:13px;font-family:inherit;background:#fafaf6">
        </div>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600">
          <input type="checkbox" name="is_published" checked style="width:16px;height:16px;accent-color:#7a7d3f"> Publish immediately
        </label>
        <button type="submit" style="padding:13px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;transition:.25s" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
          📰 Publish Post
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT MODAL ── -->
<div id="evtEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:20px;padding:32px;max-width:580px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
      <h3 style="font-size:18px;font-weight:800">✏️ Edit Event / News Post</h3>
      <button onclick="document.getElementById('evtEditModal').style.display='none'" style="background:#fee2e2;border:none;border-radius:8px;padding:6px 12px;cursor:pointer;font-weight:700;color:#991b1b;font-size:13px">✕ Close</button>
    </div>
    <form method="POST" action="../api/admin_events.php" enctype="multipart/form-data">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editEvtId">
      <div style="display:grid;gap:14px">
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">TITLE *</label>
          <input type="text" name="title" id="editEvtTitle" required style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:14px;font-family:inherit;outline:none;transition:.2s" onfocus="this.style.borderColor='#7a7d3f'" onblur="this.style.borderColor='#e0ddd5'">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">CONTENT *</label>
          <textarea name="content" id="editEvtContent" required rows="4" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:14px;font-family:inherit;outline:none;resize:vertical;transition:.2s" onfocus="this.style.borderColor='#7a7d3f'" onblur="this.style.borderColor='#e0ddd5'"></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">CATEGORY</label>
            <select name="category" id="editEvtCat" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:13px;font-family:inherit;outline:none;background:#fff">
              <option value="news">📰 News</option>
              <option value="event">🎉 Event</option>
              <option value="drive">💚 Drive</option>
              <option value="milestone">🏆 Milestone</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">EMOJI</label>
            <input type="text" name="emoji" id="editEvtEmoji" maxlength="10" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-family:inherit;outline:none;text-align:center;font-size:1.4rem">
          </div>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">EVENT DATE (optional)</label>
          <input type="date" name="event_date" id="editEvtDate" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:13px;font-family:inherit;outline:none">
        </div>
        <div id="editImgPreview" style="display:none;margin-bottom:4px">
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">CURRENT IMAGE</label>
          <img id="editImgThumb" src="" alt="" style="width:100%;max-height:140px;object-fit:cover;border-radius:10px;border:1px solid #ede9df">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px">REPLACE IMAGE (optional)</label>
          <input type="file" name="image" accept="image/*" style="width:100%;padding:10px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:13px;font-family:inherit;background:#fafaf6">
        </div>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600">
          <input type="checkbox" name="is_published" id="editEvtPublished" style="width:16px;height:16px;accent-color:#7a7d3f"> Published
        </label>
        <button type="submit" style="padding:13px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;transition:.25s" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
          💾 Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(ev) {
  document.getElementById('editEvtId').value        = ev.id;
  document.getElementById('editEvtTitle').value     = ev.title;
  document.getElementById('editEvtContent').value   = ev.content;
  document.getElementById('editEvtEmoji').value     = ev.emoji || '📰';
  document.getElementById('editEvtDate').value      = ev.event_date || '';
  document.getElementById('editEvtPublished').checked = ev.is_published == 1;
  const catEl = document.getElementById('editEvtCat');
  if (catEl) catEl.value = ev.category || 'news';
  const imgPrev = document.getElementById('editImgPreview');
  const imgThumb = document.getElementById('editImgThumb');
  if (ev.image) {
    // Cloudinary URLs are absolute; local fallback paths need APP_URL prefix
    imgThumb.src = ev.image.startsWith('http') ? ev.image : '../' + ev.image;
    imgPrev.style.display = 'block';
  } else {
    imgPrev.style.display = 'none';
  }
  document.getElementById('evtEditModal').style.display = 'flex';
}
// Close modals on backdrop click
['evtCreateModal','evtEditModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e){
    if (e.target === this) this.style.display = 'none';
  });
});
</script>
</div>

<!-- ══════════════════ MESSAGES TAB ══════════════════ -->
<div id="tab-contacts" class="tab-panel <?=$tab==='contacts'?'active':''?>">
  <div class="sec-head"><h3>📬 Contact Messages <span class="sec-count"><?=$stats['contact_msgs']?></span></h3></div>
  <div class="table-wrap"><div class="table-scroll"><table>
    <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Subject</th><th>Message</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($contacts as $i=>$cm): ?>
    <tr>
      <td style="color:var(--muted);font-weight:700"><?=$i+1?></td>
      <td>
        <div class="u-info">
          <div class="u-avatar" style="background:linear-gradient(135deg,#3b82f6,#2563eb);font-size:12px"><?=strtoupper(mb_substr($cm['name'],0,1))?></div>
          <strong><?=htmlspecialchars($cm['name'])?></strong>
        </div>
      </td>
      <td style="font-size:11px"><?=htmlspecialchars($cm['email'])?></td>
      <td style="font-size:12px"><span style="background:#f0ede5;padding:2px 8px;border-radius:6px;font-weight:600;font-size:11px"><?=htmlspecialchars($cm['subject']??'General')?></span></td>
      <td style="max-width:300px;font-size:12px;color:var(--muted);line-height:1.5"><?=htmlspecialchars(mb_substr($cm['message'],0,100)).(mb_strlen($cm['message'])>100?'…':'')?></td>
      <td style="font-size:11px;white-space:nowrap"><?=date('d M Y · h:i A',strtotime($cm['created_at']))?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($contacts)): ?><tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">No messages yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- ══════════════════ AI INSIGHTS TAB ══════════════════ -->
<div id="tab-ai" class="tab-panel <?=$tab==='ai'?'active':''?>">
<div class="sec-head"><h3>🤖 AI Insights &amp; Analytics</h3><span class="sec-count">Live · <?=date('h:i A')?></span></div>

<!-- AI Recommendations -->
<div style="display:grid;gap:10px;margin-bottom:28px">
  <?php foreach($ai_recs as $r): ?>
  <div style="background:var(--card);border-radius:12px;padding:14px 18px;box-shadow:var(--shadow);display:flex;align-items:center;gap:14px;border-left:4px solid <?=$r['type']==='urgent'?'#ef4444':($r['type']==='warn'?'#f59e0b':($r['type']==='success'?'#10b981':'#3b82f6'))?>">
    <span style="font-size:1.4rem;flex-shrink:0"><?=$r['icon']?></span>
    <span style="font-size:13px;font-weight:600;color:var(--text)"><?=$r['msg']?></span>
    <span style="margin-left:auto;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;background:<?=$r['type']==='urgent'?'#fee2e2':($r['type']==='warn'?'#fef3c7':($r['type']==='success'?'#d1fae5':'#dbeafe'))?>;color:<?=$r['type']==='urgent'?'#991b1b':($r['type']==='warn'?'#92400e':($r['type']==='success'?'#065f46':'#1e40af'))?>"><?=strtoupper($r['type'])?></span>
  </div>
  <?php endforeach; ?>
</div>

<!-- AI Impact + Forecast row -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:28px">
  <div class="chart-card" style="text-align:center">
    <h4>👥 People Impacted</h4>
    <div style="font-size:3rem;font-weight:900;color:var(--accent)"><?=number_format($ai_impact['people_fed'])?></div>
    <div style="font-size:12px;color:var(--muted);margin-top:5px">AI estimate: <?=$ai_impact['food_delivered']?> food units × 3.2 people avg</div>
  </div>
  <div class="chart-card" style="text-align:center">
    <h4>🌱 CO₂ Saved</h4>
    <div style="font-size:3rem;font-weight:900;color:#059669"><?=number_format($ai_impact['co2_saved_kg'])?> <small style="font-size:1.2rem">kg</small></div>
    <div style="font-size:12px;color:var(--muted);margin-top:5px">Environmental impact of all donations</div>
  </div>
  <div class="chart-card" style="text-align:center">
    <h4>📈 Next Week Forecast</h4>
    <div style="font-size:3rem;font-weight:900;color:#3b82f6"><?=$ai_forecast['predicted_next']?></div>
    <div style="font-size:12px;color:var(--muted);margin-top:5px">Predicted donations · Trend: <strong><?=$ai_forecast['trend']?></strong></div>
  </div>
</div>

<!-- Weekly Chart -->
<div class="chart-card" style="margin-bottom:20px">
  <h4>📊 Weekly Donation Volume (Last 8 Weeks)</h4>
  <div style="position:relative;height:260px"><canvas id="weeklyAdminChart"></canvas></div>
</div>
</div>

<!-- ══════════════════ TASK ASSIGN TAB ══════════════════ -->
<div id="tab-assign" class="tab-panel <?=$tab==='assign'?'active':''?>">
<div class="sec-head"><h3>🎯 Volunteer Task Assignment <span class="sec-count">AI-Powered</span></h3></div>
<div style="background:#e0f2fe;border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#0369a1;font-weight:600;display:flex;align-items:center;gap:10px">
  🤖 <span>Click <strong>"AI Assign"</strong> to let the AI automatically choose the best volunteer based on city match, past performance, and current workload.</span>
</div>

<h4 style="font-size:14px;font-weight:800;margin-bottom:12px">🍲 Accepted Food Donations</h4>
<?php if(empty($pending_food)): ?>
<div class="empty-box" style="margin-bottom:20px"><div class="ei">✅</div><p>No accepted food donations awaiting assignment.</p></div>
<?php else: ?>
<div class="table-wrap" style="margin-bottom:24px"><div class="table-scroll"><table>
  <thead><tr><th>#</th><th>Donor</th><th>Qty</th><th>Priority</th><th>Address</th><th>Pickup Date</th><th>Pickup Time</th><th>AI Auto-Assign</th></tr></thead>
  <tbody>
  <?php foreach($pending_food as $d): ?>
  <tr id="food-row-<?=(int)$d['id']?>">
    <td style="font-weight:700;color:var(--muted)"><?=(int)$d['id']?></td>
    <td style="font-size:11px;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($d['donor_email'])?></td>
    <td><strong><?=(int)$d['quantity']?></strong></td>
    <td><span class="pri <?=$d['priority']??'medium'?>"><?=ucfirst($d['priority']??'medium')?></span></td>
    <td style="font-size:12px;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($d['pickup_address'])?></td>
    <td><input type="date" id="fd-date-<?=(int)$d['id']?>" value="<?=date('Y-m-d',strtotime('+1 day'))?>" style="padding:5px 8px;border:1.5px solid #ddd;border-radius:7px;font-size:11px;font-family:inherit"></td>
    <td><input type="text" id="fd-time-<?=(int)$d['id']?>" value="10 AM – 12 PM" style="width:110px;padding:5px 8px;border:1.5px solid #ddd;border-radius:7px;font-size:11px;font-family:inherit"></td>
    <td>
      <button class="btn btn-accept" onclick="aiAssign(<?=(int)$d['id']?>,'food',this)" style="background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff">🤖 AI Assign</button>
      <div id="ai-result-food-<?=(int)$d['id']?>" style="font-size:11px;margin-top:5px;color:var(--muted)"></div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>

<h4 style="font-size:14px;font-weight:800;margin-bottom:12px">👕 Accepted Cloth Donations</h4>
<?php if(empty($pending_cloth)): ?>
<div class="empty-box"><div class="ei">✅</div><p>No accepted cloth donations awaiting assignment.</p></div>
<?php else: ?>
<div class="table-wrap"><div class="table-scroll"><table>
  <thead><tr><th>#</th><th>Donor</th><th>Qty</th><th>Address</th><th>Pickup Date</th><th>Pickup Time</th><th>AI Auto-Assign</th></tr></thead>
  <tbody>
  <?php foreach($pending_cloth as $d): ?>
  <tr id="cloth-row-<?=(int)$d['id']?>">
    <td style="font-weight:700;color:var(--muted)"><?=(int)$d['id']?></td>
    <td style="font-size:11px;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($d['donor_email'])?></td>
    <td><strong><?=(int)$d['quantity']?></strong></td>
    <td style="font-size:12px;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($d['pickup_address'])?></td>
    <td><input type="date" id="cd-date-<?=(int)$d['id']?>" value="<?=date('Y-m-d',strtotime('+1 day'))?>" style="padding:5px 8px;border:1.5px solid #ddd;border-radius:7px;font-size:11px;font-family:inherit"></td>
    <td><input type="text" id="cd-time-<?=(int)$d['id']?>" value="10 AM – 12 PM" style="width:110px;padding:5px 8px;border:1.5px solid #ddd;border-radius:7px;font-size:11px;font-family:inherit"></td>
    <td>
      <button class="btn btn-accept" onclick="aiAssign(<?=(int)$d['id']?>,'cloth',this)" style="background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff">🤖 AI Assign</button>
      <div id="ai-result-cloth-<?=(int)$d['id']?>" style="font-size:11px;margin-top:5px;color:var(--muted)"></div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
</div>

<!-- ══════════════════ PAYOUT TAB ══════════════════ -->
<div id="tab-payout" class="tab-panel <?=$tab==='payout'?'active':''?>">
<div class="sec-head"><h3>💰 Seller Payouts <span class="sec-count">Settlement Tracker</span></h3></div>

<?php if(!empty($pending_payouts)): ?>
<h4 style="font-size:14px;font-weight:800;margin-bottom:12px;color:#d97706">⏳ Pending Settlements</h4>
<div class="table-wrap" style="margin-bottom:28px"><div class="table-scroll"><table>
  <thead><tr><th>Seller</th><th>Store</th><th>Orders</th><th>Amount Due</th><th>UPI / Bank</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($pending_payouts as $pp): ?>
  <tr>
    <td style="font-size:11px"><?=htmlspecialchars($pp['seller_email'])?></td>
    <td style="font-weight:600"><?=htmlspecialchars($pp['store_name']??'—')?></td>
    <td style="font-weight:700"><?=(int)$pp['cnt']?></td>
    <td><strong style="color:var(--accent)">₹<?=number_format((float)$pp['total'],2)?></strong></td>
    <td style="font-size:11px"><?=htmlspecialchars($pp['upi_id']??($pp['bank_account']?'Bank: '.$pp['bank_account']:'—'))?></td>
    <td>
      <form method="POST" action="../api/process_payout.php" style="display:inline">
        <?=csrf_field()?>
        <input type="hidden" name="seller_email" value="<?=htmlspecialchars($pp['seller_email'])?>">
        <input type="hidden" name="amount" value="<?=(float)$pp['total']?>">
        <input type="text" name="reference" placeholder="UTR/Ref#" style="width:90px;padding:5px 8px;border:1.5px solid #ddd;border-radius:7px;font-size:11px;font-family:inherit;margin-right:4px">
        <button class="btn btn-accept">✓ Mark Paid</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php else: ?>
<div class="empty-box" style="margin-bottom:28px"><div class="ei">💰</div><p>No pending settlements. All sellers are paid up.</p></div>
<?php endif; ?>

<h4 style="font-size:14px;font-weight:800;margin-bottom:12px">📋 Settlement History</h4>
<?php if(empty($settlements)): ?>
<div class="empty-box"><div class="ei">📋</div><p>No settlement records yet.</p></div>
<?php else: ?>
<div class="table-wrap"><div class="table-scroll"><table>
  <thead><tr><th>#</th><th>Seller</th><th>Amount</th><th>Method</th><th>Reference</th><th>Orders</th><th>Period</th><th>Status</th><th>Paid At</th></tr></thead>
  <tbody>
  <?php foreach($settlements as $i=>$s): ?>
  <tr>
    <td style="color:var(--muted);font-weight:700"><?=$i+1?></td>
    <td style="font-size:11px"><?=htmlspecialchars($s['seller_email'])?><br><small style="color:var(--muted)"><?=htmlspecialchars($s['store_name']??'')?></small></td>
    <td><strong>₹<?=number_format((float)$s['amount'],2)?></strong></td>
    <td style="font-size:12px"><?=strtoupper($s['method']??'—')?></td>
    <td style="font-size:11px"><?=htmlspecialchars($s['reference']??'—')?></td>
    <td style="font-weight:700"><?=(int)$s['orders_count']?></td>
    <td style="font-size:11px"><?=date('d M',strtotime($s['period_from']))?> – <?=date('d M Y',strtotime($s['period_to']))?></td>
    <td><span class="pill <?=$s['status']==='paid'?'accepted':($s['status']==='cancelled'?'rejected':'pending')?>"><?=ucfirst($s['status'])?></span></td>
    <td style="font-size:11px"><?=$s['paid_at']?date('d M Y',strtotime($s['paid_at'])):'—'?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
</div>

</main>
</div><!-- .app -->

<div id="dashToast"></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="../js/dashboard.js"></script>
<script>
/* ── Admin greeting ── */
(function(){
  const el = document.getElementById('adminTime');
  if (!el) return;
  const h = new Date().getHours();
  const g = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  el.textContent = g + ', Admin';
})();

/* ── Tab switch ── */
function sw(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
  const panel = document.getElementById('tab-' + name);
  if (panel) panel.classList.add('active');
  if (btn)   btn.classList.add('active');
  history.replaceState(null, '', '?tab=' + name);
  setTimeout(() => animateBars('#tab-' + name + ' .bar-fill'), 50);
  if (name === 'ai' && !window._adminChartBuilt) buildAdminChart();
}

/* ── Animate bar fills ── */
function animateBars(sel) {
  document.querySelectorAll(sel).forEach(b => {
    const target = b.getAttribute('data-w') || '0%';
    b.style.width = '0';
    requestAnimationFrame(() => { requestAnimationFrame(() => { b.style.width = target; }); });
  });
}
document.addEventListener('DOMContentLoaded', () => {
  animateBars('.bar-fill');
  if (<?=$tab==='ai'?'true':'false'?>) buildAdminChart();
});

/* ── Weekly Admin Chart ── */
function buildAdminChart() {
  if (window._adminChartBuilt) return;
  window._adminChartBuilt = true;
  const ctx = document.getElementById('weeklyAdminChart');
  if (!ctx) return;
  new Chart(ctx, {
    type:'line',
    data:{
      labels: <?=json_encode($w_labels)?>,
      datasets:[
        {label:'Food',data:<?=json_encode($w_food)?>,borderColor:'#006D77',backgroundColor:'rgba(0,109,119,.12)',tension:.4,fill:true,pointRadius:4},
        {label:'Clothing',data:<?=json_encode($w_cloth)?>,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.08)',tension:.4,fill:true,pointRadius:4}
      ]
    },
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:12,weight:'700'},padding:14}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:11}}},x:{grid:{display:false},ticks:{font:{size:10},maxRotation:45}}}}
  });
}

/* ── AI Auto-Assign ── */
async function aiAssign(id, type, btn) {
  const pfx = type === 'food' ? 'fd' : 'cd';
  const pd  = document.getElementById(pfx+'-date-'+id)?.value || '';
  const pt  = document.getElementById(pfx+'-time-'+id)?.value || '';
  const resultEl = document.getElementById('ai-result-'+type+'-'+id);

  btn.disabled = true;
  btn.textContent = '⏳ AI working…';

  const fd = new FormData();
  fd.append('donation_id',   id);
  fd.append('donation_type', type);
  fd.append('pickup_date',   pd);
  fd.append('pickup_time',   pt);
  fd.append('csrf_token',    '<?=csrf_token()?>');

  try {
    const r = await fetch('../api/ai_auto_assign.php', { method:'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      btn.textContent = '✅ Assigned!';
      btn.style.background = '#065f46';
      if (resultEl) resultEl.innerHTML = `<strong>🤖 ${d.volunteer_name}</strong> (score: ${d.score}/100)${d.city_match?' · 📍 City match':''}`;
      showToast(`AI assigned ${d.volunteer_name} — score ${d.score}/100`, 'success');
      setTimeout(() => location.reload(), 3000);
    } else {
      btn.textContent = '❌ Failed';
      btn.style.background = '#dc2626';
      if (resultEl) resultEl.textContent = d.message || 'Assignment failed';
      showToast(d.message || 'AI assignment failed', 'error');
      setTimeout(() => { btn.disabled=false; btn.textContent='🤖 AI Assign'; btn.style.background=''; }, 3000);
    }
  } catch (e) {
    btn.disabled = false; btn.textContent = '🤖 AI Assign'; btn.style.background = '';
    showToast('Network error. Try again.', 'error');
  }
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
