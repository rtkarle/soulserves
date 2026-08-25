<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
require_once __DIR__ . '/../api/ai_engine.php';

/* ── Role-based access guard ── */
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
/* If logged in but NOT a seller, redirect to their correct dashboard */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'seller') {
    /* Fix session role from DB if stale */
    require_once __DIR__ . '/../config/db.php';
    $chk = $conn->prepare("SELECT role FROM register WHERE email=? AND verified=1");
    $chk->bind_param("s", $_SESSION['user_email']); $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if ($row) {
        $_SESSION['role'] = $row['role'];
        switch ($row['role']) {
            case 'donor':     header("Location: ../donor/donor_dashboard.php");       exit;
            case 'volunteer': header("Location: ../volunteer/volunteer_dashboard.php"); exit;
            case 'admin':     header("Location: ../admin/admin_dashboard.php");        exit;
        }
    }
    header("Location: ../auth/login.php"); exit;
}
$email = $_SESSION['user_email'];
$me = mysqli_real_escape_string($conn, $email);

/* ── Safe helpers ── */
function sq_i($c,$s):int{ try{$r=$c->query($s);return($r&&$row=$r->fetch_assoc())?(int)$row['c']:0;}catch(Throwable $e){return 0;} }
function sq_f($c,$s):float{ try{$r=$c->query($s);return($r&&$row=$r->fetch_assoc())?(float)$row['r']:0.0;}catch(Throwable $e){return 0.0;} }

$uq = $conn->prepare("SELECT name FROM register WHERE email=? AND role='seller' AND verified=1");
$uq->bind_param("s",$email); $uq->execute();
$user = $uq->get_result()->fetch_assoc();
if (!$user) { header("Location: ../auth/login.php"); exit; }

/* ── Store ── */
$store=null;
try{$sq2=$conn->prepare("SELECT * FROM seller_stores WHERE seller_email=?");$sq2->bind_param("s",$email);$sq2->execute();$store=$sq2->get_result()->fetch_assoc();}catch(Throwable $e){}

/* ── Counts ── */
$total_products=$total_orders=$pending_orders=$low_stock=0;$total_revenue=0.0;
if($store){
  $total_products = sq_i($conn,"SELECT COUNT(*) c FROM products WHERE seller_email='$me' AND is_active=1");
  $total_orders   = sq_i($conn,"SELECT COUNT(*) c FROM orders WHERE seller_email='$me'");
  $total_revenue  = sq_f($conn,"SELECT COALESCE(SUM(total_amount),0) r FROM orders WHERE seller_email='$me' AND order_status NOT IN ('cancelled','returned')");
  $pending_orders = sq_i($conn,"SELECT COUNT(*) c FROM orders WHERE seller_email='$me' AND order_status='placed'");
  $low_stock      = sq_i($conn,"SELECT COUNT(*) c FROM products WHERE seller_email='$me' AND stock<=5 AND is_active=1");
}

/* ── Products ── */
$products=[];
try{$pq=$conn->prepare("SELECT p.*,(SELECT COUNT(*) FROM product_reviews WHERE product_id=p.id) rev_count FROM products p WHERE p.seller_email=? ORDER BY p.created_at DESC");$pq->bind_param("s",$email);$pq->execute();$products=$pq->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}

/* ── Orders ── */
$orders=[];
try{$oq=$conn->prepare("SELECT o.*,GROUP_CONCAT(oi.product_name SEPARATOR ', ') AS items FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE o.seller_email=? GROUP BY o.id ORDER BY o.created_at DESC LIMIT 50");$oq->bind_param("s",$email);$oq->execute();$orders=$oq->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}

/* ── Weekly revenue chart data ── */
$weekly_rev=[]; $weekly_labels=[];
try{
  for($i=6;$i>=0;$i--){
    $from=date('Y-m-d',strtotime("-$i days"));
    $to=date('Y-m-d',strtotime("-".($i-1)." days"));
    $rev=(float)$conn->query("SELECT COALESCE(SUM(total_amount),0) r FROM orders WHERE seller_email='$me' AND DATE(created_at)='$from' AND order_status NOT IN ('cancelled','returned')")->fetch_assoc()['r'];
    $weekly_rev[]=$rev;
    $weekly_labels[]=date('D',strtotime($from));
  }
}catch(Throwable $e){}

/* ── AI Engine ── */
$ai=adhaar_ai();
$ai_recs=$ai_demand=$ai_pricing=[];
try{
  $ai_recs   = $ai->getSellerRecommendations($email) ?: [];
  $ai_demand = $ai->sellerDemandForecast($email) ?: [];
} catch(Throwable $e){}

/* ── Fraud check per order ── */
$fraud_flags=[];
foreach(array_slice($orders,0,10) as $o){
  try{$f=$ai->detectOrderFraud((int)$o['id']);if(($f['risk']??'low')!=='low')$fraud_flags[$o['id']]=$f;}catch(Throwable $e){}
}

/* ── Sentiment per product ── */
$sentiment=[];
foreach(array_slice($products,0,5) as $p){
  try{if((int)($p['rev_count']??0)>0)$sentiment[$p['id']]=$ai->analyzeReviewSentiment((int)$p['id']);}catch(Throwable $e){}
}

/* ── Pricing suggestion per product ── */
$pricing=[];
foreach(array_slice($products,0,5) as $p){
  try{$pricing[$p['id']]=$ai->suggestPricing((int)$p['id']);}catch(Throwable $e){}
}

$tab    = $_GET['tab']     ?? 'overview';
$success= $_GET['success'] ?? '';
$err    = $_GET['err']     ?? '';
$cats   = ['handicraft'=>'Handicraft','textile'=>'Textile','food_product'=>'Food Product',
           'jewelry'=>'Jewelry','art'=>'Art','pottery'=>'Pottery','organic'=>'Organic','other'=>'Other'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Seller Dashboard — SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#102A43">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/dashboard.css">
<style>
:root{--st:#006D77;--sg:#2E8B57;--so:#FF8A00;--sgr:linear-gradient(135deg,#006D77,#2E8B57);--shr:linear-gradient(135deg,#102A43 0%,#006D77 55%,#2E8B57 100%)}
.swb{background:var(--shr);border-radius:24px;padding:28px 30px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;box-shadow:0 16px 48px rgba(0,109,119,.25);animation:sfu .5s ease}
@keyframes sfu{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.swb::before{content:'';position:absolute;right:-50px;top:-50px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none}
.swb-t{font-size:20px;font-weight:800;margin-bottom:4px}.swb-s{font-size:13px;opacity:.75}
.swb-b{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.swb-btn{padding:9px 18px;border-radius:20px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;border:none;transition:.2s}
.swb-btn.white{background:#fff;color:var(--st)}.swb-btn.white:hover{background:#e8fdf5;transform:translateY(-1px)}
.swb-btn.glass{background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.3)}.swb-btn.glass:hover{background:rgba(255,255,255,.25)}
.skpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.skpi{background:#fff;border-radius:18px;padding:18px 16px;box-shadow:0 4px 20px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s;position:relative;overflow:hidden}
.skpi:hover{transform:translateY(-4px);box-shadow:0 12px 36px rgba(16,42,67,.12)}
.skpi::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 18px 18px;opacity:0;transition:.3s}
.skpi:hover::after{opacity:1}
.skpi.c1::after{background:var(--sgr)}.skpi.c2::after{background:linear-gradient(135deg,var(--so),#F72585)}
.skpi.c3::after{background:linear-gradient(135deg,#2563EB,#7B2CBF)}.skpi.c4::after{background:linear-gradient(135deg,#dc2626,#f87171)}
.skpi.c5::after{background:linear-gradient(135deg,var(--sg),var(--st))}
.skpi-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:10px}
.skpi-val{font-size:28px;font-weight:900;color:var(--navy);line-height:1;margin-bottom:3px}
.skpi-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
.ai-s-panel{background:linear-gradient(135deg,#0a1f30,#0d3858 40%,#006d77 100%);border-radius:22px;padding:0;overflow:hidden;margin-bottom:24px;box-shadow:0 12px 40px rgba(0,109,119,.2)}
.ai-s-hdr{padding:16px 22px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.1)}
.ai-s-hdr-icon{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.ai-s-hdr h3{font-size:15px;font-weight:800;color:#fff;margin-bottom:2px}
.ai-s-hdr p{font-size:11px;color:rgba(255,255,255,.55)}
.ai-live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;animation:lp 1.6s ease infinite;margin-right:4px}
@keyframes lp{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}
.ai-s-body{padding:16px 22px;display:flex;flex-direction:column;gap:9px}
.ai-s-sug{display:flex;align-items:flex-start;gap:12px;padding:12px 15px;border-radius:13px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.08);transition:.2s}
.ai-s-sug:hover{background:rgba(255,255,255,.12)}
.ai-s-sug-icon{font-size:18px;flex-shrink:0;margin-top:1px}
.ai-s-sug-text{font-size:13px;color:rgba(255,255,255,.85);line-height:1.65}.ai-s-sug-text strong{color:#fff}
.demand-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:24px}
.demand-card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s;position:relative;overflow:hidden}
.demand-card:hover{transform:translateY(-4px)}.demand-card-score{font-size:28px;font-weight:900;color:var(--navy);line-height:1}
.demand-card-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
.demand-bar-wrap{height:5px;background:rgba(16,42,67,.08);border-radius:6px;overflow:hidden;margin-bottom:8px}
.demand-bar{height:100%;background:var(--sgr);border-radius:6px;transition:width 1.2s cubic-bezier(.22,1,.36,1)}
.demand-advice{font-size:11px;color:var(--muted);line-height:1.55}
.trending-badge{position:absolute;top:12px;right:12px;background:linear-gradient(135deg,#FF8A00,#F72585);color:#fff;font-size:9px;font-weight:800;padding:3px 9px;border-radius:20px;text-transform:uppercase}
.form-card{background:#fff;border-radius:20px;box-shadow:0 4px 16px rgba(16,42,67,.07);padding:28px}
.form-card h3{font-size:18px;font-weight:800;margin-bottom:6px}.fc-sub{font-size:13px;color:var(--muted);margin-bottom:22px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.field{margin-bottom:0}.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px}
.field input,.field select,.field textarea{width:100%;padding:11px 14px;border:1.5px solid #e2ebe9;border-radius:10px;font-size:14px;font-family:inherit;color:var(--navy);transition:.2s;outline:none;background:#fafbfa}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(0,109,119,.1);background:#fff}
.field textarea{resize:vertical;min-height:90px}.field.full{grid-column:1/-1}
.form-btn{padding:12px 24px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:.25s;background:var(--sgr);color:#fff;box-shadow:0 4px 14px rgba(0,109,119,.3)}
.form-btn:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(0,109,119,.4)}
.btn-sm{padding:7px 14px;font-size:12px;border-radius:8px;border:none;cursor:pointer;font-weight:700;transition:.25s}
.btn-warn{background:#fef3c7;color:#92400e}.btn-warn:hover{background:#fde68a}
.btn-ok{background:#d1fae5;color:#065f46}.btn-ok:hover{background:#a7f3d0}
.btn-pri{background:var(--sgr);color:#fff}
.prod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:14px;margin-top:18px}
.prod-card{background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 14px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s}
.prod-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(16,42,67,.1)}
.prod-img{width:100%;height:140px;object-fit:cover;background:linear-gradient(135deg,#edf5f2,#eef2ff)}
.prod-img-ph{width:100%;height:140px;display:flex;align-items:center;justify-content:center;font-size:42px;background:linear-gradient(135deg,#edf5f2,#eef2ff)}
.prod-body{padding:14px}
.prod-name{font-size:14px;font-weight:700;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.prod-price{font-size:16px;font-weight:800;color:var(--st);margin-bottom:2px}
.prod-mrp{font-size:12px;color:var(--muted);text-decoration:line-through;margin-left:6px}
.prod-meta{font-size:12px;color:var(--muted);margin-bottom:8px}
.prod-ai-row{background:rgba(0,109,119,.05);border-radius:8px;padding:8px 10px;font-size:11px;color:var(--navy);margin-bottom:8px;line-height:1.55}
.prod-actions{display:flex;gap:6px}
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase}
.pill.placed{background:#fef3c7;color:#92400e}.pill.confirmed{background:#dbeafe;color:#1e40af}
.pill.shipped{background:#ede9fe;color:#5b21b6}.pill.out_for_delivery{background:#fce7f3;color:#9d174d}
.pill.delivered{background:#d1fae5;color:#065f46}.pill.cancelled,.pill.returned{background:#fee2e2;color:#991b1b}
.fraud-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px}
.fraud-badge.high{background:#fee2e2;color:#991b1b}.fraud-badge.medium{background:#fef3c7;color:#92400e}.fraud-badge.low{background:#d1fae5;color:#065f46}
.sc-chat-panel{background:#fff;border-radius:22px;border:1px solid rgba(16,42,67,.06);box-shadow:0 4px 20px rgba(16,42,67,.07);overflow:hidden;margin-bottom:24px}
.sc-chat-hdr{background:var(--shr);padding:14px 20px;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.sc-chat-hdr h4{font-size:14px;font-weight:800;color:#fff;margin:0;flex:1}
.sc-msgs{height:240px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth;background:#f9fbfa}
.smsg{display:flex;gap:8px;align-items:flex-end;animation:sfu .2s ease}
.smsg.bot{flex-direction:row}.smsg.user{flex-direction:row-reverse}
.sbub{max-width:80%;padding:10px 14px;border-radius:16px;font-size:13px;line-height:1.6}
.smsg.bot .sbub{background:#fff;color:var(--navy);border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px;box-shadow:0 2px 8px rgba(16,42,67,.06)}
.smsg.user .sbub{background:var(--sgr);color:#fff;border-radius:16px 4px 16px 16px}
.sico{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.smsg.bot .sico{background:var(--sgr);color:#fff}.smsg.user .sico{background:#e2ebe9;color:var(--navy)}
.sc-input-row{display:flex;border-top:1px solid rgba(16,42,67,.08)}
.sc-input{flex:1;padding:12px 16px;border:none;outline:none;font-size:13px;font-family:inherit}
.sc-send{padding:0 18px;background:var(--sgr);color:#fff;border:none;cursor:pointer;font-size:15px}
.sc-quick{padding:8px 14px 10px;display:flex;gap:6px;flex-wrap:wrap;background:#f9fbfa;border-top:1px solid rgba(16,42,67,.06)}
.sqb{padding:5px 12px;border-radius:20px;border:1.5px solid rgba(0,109,119,.2);background:#fff;font-size:11px;font-weight:600;color:var(--st);cursor:pointer;transition:.2s;font-family:inherit}
.sqb:hover{background:var(--st);color:#fff;border-color:var(--st)}
.typing-dots{display:flex;gap:4px;align-items:center;padding:10px 14px;background:#fff;border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px;width:fit-content}
.typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:td 1.2s infinite}
.typing-dots span:nth-child(2){animation-delay:.2s}.typing-dots span:nth-child(3){animation-delay:.4s}
@keyframes td{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.sec-head h3{font-size:15px;font-weight:800;color:var(--navy)}
.sec-head button,.sec-head a{font-size:12px;font-weight:700;color:var(--teal);padding:5px 13px;border-radius:20px;background:rgba(0,109,119,.08);border:none;cursor:pointer;text-decoration:none;transition:.2s}
.sec-head button:hover,.sec-head a:hover{background:rgba(0,109,119,.15)}
.no-store{background:#fef3c7;border:1.5px solid #fde68a;border-radius:14px;padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.empty-state{background:#f9fbfa;border:1px dashed rgba(16,42,67,.12);border-radius:16px;padding:40px;text-align:center;color:var(--muted)}
.empty-state .emoji{font-size:40px;display:block;margin-bottom:12px}
.desc-result{background:linear-gradient(135deg,rgba(0,109,119,.06),rgba(46,139,87,.04));border:1.5px solid rgba(0,109,119,.2);border-radius:14px;padding:16px;font-size:13px;color:var(--navy);line-height:1.7;margin-top:14px;display:none}
@media(max-width:1100px){.skpi-row{grid-template-columns:repeat(3,1fr)}.prod-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .skpi-row{grid-template-columns:repeat(3,1fr)}
  .demand-grid{grid-template-columns:repeat(2,1fr)}
  .prod-grid{grid-template-columns:repeat(2,1fr)}
  .swb{padding:20px 22px;border-radius:18px}
}
@media(max-width:700px){
  .skpi-row{grid-template-columns:repeat(2,1fr);gap:10px}
  .demand-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .prod-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .form-grid{grid-template-columns:1fr 1fr}
  .page{padding:14px}
  .swb{padding:16px;border-radius:16px}
  .swb-t{font-size:18px}
  .sc-msgs{height:200px}
  .ai-s-panel{border-radius:16px}
}
@media(max-width:480px){
  .skpi-row{grid-template-columns:1fr 1fr;gap:8px}
  .skpi-val{font-size:22px}
  .skpi-icon{width:36px;height:36px;font-size:17px;margin-bottom:8px}
  .demand-grid{grid-template-columns:1fr}
  .prod-grid{grid-template-columns:1fr 1fr;gap:8px}
  .form-grid{grid-template-columns:1fr}
  .page{padding:12px}
  .swb{padding:14px;border-radius:14px}
  .swb-t{font-size:16px}
  .swb-b{gap:6px}
  .swb-btn{padding:7px 13px;font-size:12px}
  .form-card{padding:20px 16px}
  .form-btn{width:100%;justify-content:center;padding:12px}
  .sc-msgs{height:180px}
  .sec-head h3{font-size:13px}
  .prod-ai-row{font-size:10px}
  .prod-body{padding:10px}
  .no-store{flex-direction:column;gap:10px}
}
</style>
</head>
<body>
<!-- MOBILE TOPBAR -->
<div class="mobile-topbar">
  <div style="padding:0 16px;height:58px;display:flex;align-items:center;justify-content:space-between">
    <img src="../assets/logo.png" alt="SoulServe" style="height:30px;object-fit:contain" loading="eager">
    <button class="hamburger" id="hamburger" aria-label="Menu" style="display:flex;flex-direction:column;gap:5px;cursor:pointer;padding:8px;background:none;border:none">
      <span style="display:block;width:22px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
      <span style="display:block;width:22px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
      <span style="display:block;width:22px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
    </button>
  </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="app">
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="sidebar-logo-mark"><img src="../assets/logo.png" alt="SoulServe" style="width:28px;height:28px;object-fit:contain;filter:brightness(0)invert(1)"></div>
    <div class="sidebar-logo-text"><strong>SoulServe</strong><span>Seller Portal</span></div>
  </div>
  <div style="margin:8px 10px 4px;padding:12px 14px;background:rgba(255,255,255,.06);border-radius:12px;border:1px solid rgba(255,255,255,.08)">
    <div style="width:36px;height:36px;border-radius:50%;background:var(--gradient-teal);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;font-weight:800;margin-bottom:8px"><?=strtoupper(substr($user['name'],0,1))?></div>
    <div style="font-size:13px;font-weight:700;color:#fff"><?=htmlspecialchars($user['name'])?></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:2px">🏪 Seller</div>
  </div>
  <div class="nav-sec">Dashboard</div>
  <button class="nav-btn <?=$tab==='overview'?'active':''?>" data-tab="overview" onclick="goTab('overview')">📊 Overview</button>
  <button class="nav-btn <?=$tab==='ai'?'active':''?>" data-tab="ai" onclick="goTab('ai')">🤖 AI Intelligence</button>
  <div class="nav-sec">Products</div>
  <button class="nav-btn <?=$tab==='store'?'active':''?>" data-tab="store" onclick="goTab('store')">🏬 My Store</button>
  <button class="nav-btn <?=$tab==='add_product'?'active':''?>" data-tab="add_product" onclick="goTab('add_product')">➕ Add Product</button>
  <button class="nav-btn <?=$tab==='products'?'active':''?>" data-tab="products" onclick="goTab('products')">📦 My Products<?php if(count($products)>0):?><span class="nav-badge green"><?=count($products)?></span><?php endif;?></button>
  <?php if($low_stock>0):?><button class="nav-btn" onclick="goTab('products')" style="color:#d97706">⚠️ Low Stock (<?=$low_stock?>)</button><?php endif;?>
  <div class="nav-sec">Business</div>
  <button class="nav-btn <?=$tab==='orders'?'active':''?>" data-tab="orders" onclick="goTab('orders')">🛒 Orders<?php if($pending_orders>0):?><span class="nav-badge"><?=$pending_orders?></span><?php endif;?></button>
  <button class="nav-btn <?=$tab==='bank'?'active':''?>" data-tab="bank" onclick="goTab('bank')">🏦 Bank Details</button>
  <div class="nav-sec">Community</div>
  <a href="../shop/shop.php" class="nav-btn">🛍️ View Shop</a>
  <div class="sidebar-footer"><a href="../auth/logout.php" class="logout-link">⇦ Logout</a></div>
</aside>
<!-- MAIN -->
<main class="main">
<div class="page">
<?php if($success):?><div class="success-banner">✅ <?=htmlspecialchars($success)?></div><?php endif;?>
<?php if($err):?><div style="background:#fee2e2;border-radius:14px;padding:14px 18px;margin-bottom:20px;font-size:14px;font-weight:600;color:#991b1b">⚠ <?=htmlspecialchars($err)?></div><?php endif;?>
<?php if(!$store):?>
<div class="no-store">
  <span style="font-size:28px">⚠️</span>
  <div><div style="font-size:14px;font-weight:700;color:#92400e;margin-bottom:2px">Set up your store first</div><div style="font-size:13px;color:#78350f">Complete your store profile before adding products.</div></div>
  <button class="btn-sm btn-pri" style="margin-left:auto" onclick="goTab('store')">Set Up Store →</button>
</div>
<?php endif;?>
<!-- ══ TAB: OVERVIEW ══ -->
<div id="tab-overview" class="tab-panel <?=$tab==='overview'?'active':''?>">
<!-- Welcome band -->
<div class="swb">
  <div style="position:relative;z-index:1">
    <div class="swb-t"><span id="greetTime">Hello</span>, <?=htmlspecialchars($user['name'])?> 🏪</div>
    <div class="swb-s"><?=$total_products?> products · <?=$total_orders?> orders · ₹<?=number_format($total_revenue,0)?> revenue</div>
  </div>
  <div class="swb-b">
    <button class="swb-btn white" onclick="goTab('add_product')">➕ Add Product</button>
    <button class="swb-btn glass" onclick="goTab('orders')">🛒 Orders</button>
    <button class="swb-btn glass" onclick="goTab('ai')">🤖 AI Insights</button>
  </div>
</div>
<!-- KPIs -->
<div class="skpi-row">
  <div class="skpi c1">
    <div class="skpi-icon" style="background:rgba(0,109,119,.1)">📦</div>
    <div class="skpi-val" data-count="<?=$total_products?>" data-suffix=""><?=$total_products?></div>
    <div class="skpi-label">Live Products</div>
  </div>
  <div class="skpi c2">
    <div class="skpi-icon" style="background:rgba(255,138,0,.1)">🛒</div>
    <div class="skpi-val" data-count="<?=$total_orders?>" data-suffix=""><?=$total_orders?></div>
    <div class="skpi-label">Total Orders</div>
  </div>
  <div class="skpi c3">
    <div class="skpi-icon" style="background:rgba(37,99,235,.1)">💰</div>
    <div class="skpi-val">₹<?=number_format($total_revenue,0)?></div>
    <div class="skpi-label">Revenue</div>
  </div>
  <div class="skpi c4">
    <div class="skpi-icon" style="background:rgba(220,38,38,.1)">⏳</div>
    <div class="skpi-val" data-count="<?=$pending_orders?>" data-suffix=""><?=$pending_orders?></div>
    <div class="skpi-label">Pending Orders</div>
  </div>
  <div class="skpi c5">
    <div class="skpi-icon" style="background:rgba(245,158,11,.1)">⚠️</div>
    <div class="skpi-val" data-count="<?=$low_stock?>" data-suffix=""><?=$low_stock?></div>
    <div class="skpi-label">Low Stock</div>
  </div>
</div>
<!-- Recent orders -->
<?php if(!empty($orders)):?>
<div class="sec-head"><h3>🕐 Recent Orders</h3><button onclick="goTab('orders')">View All →</button></div>
<div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);margin-bottom:24px">
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:600px">
    <thead><tr style="background:rgba(16,42,67,.03)">
      <th style="padding:11px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.06);text-align:left">Order #</th>
      <th style="padding:11px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.06);text-align:left">Items</th>
      <th style="padding:11px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.06);text-align:left">Amount</th>
      <th style="padding:11px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.06);text-align:left">Status</th>
      <th style="padding:11px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.06);text-align:left">Fraud</th>
    </tr></thead>
    <tbody>
    <?php foreach(array_slice($orders,0,5) as $o):
      $ff = $fraud_flags[(int)$o['id']] ?? null;
    ?>
    <tr style="transition:.15s" onmouseover="this.style.background='rgba(0,109,119,.02)'" onmouseout="this.style.background=''">
      <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid rgba(16,42,67,.04)"><strong><?=htmlspecialchars($o['order_number'])?></strong><br><span style="font-size:11px;color:var(--muted)"><?=date('d M Y',strtotime($o['created_at']))?></span></td>
      <td style="padding:12px 14px;font-size:12px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.04);max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($o['items']??'—')?></td>
      <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid rgba(16,42,67,.04)"><strong>₹<?=number_format($o['total_amount'],0)?></strong></td>
      <td style="padding:12px 14px;border-bottom:1px solid rgba(16,42,67,.04)"><span class="pill <?=htmlspecialchars($o['order_status'])?>"><?=ucfirst(str_replace('_',' ',$o['order_status']))?></span></td>
      <td style="padding:12px 14px;border-bottom:1px solid rgba(16,42,67,.04)">
        <?php if($ff):?><span class="fraud-badge <?=$ff['risk']?>">⚠ <?=$ff['risk']?></span><?php else:?><span class="fraud-badge low">✓ Clean</span><?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
</div>
<?php endif;?>
</div><!-- /overview -->
<!-- ══ TAB: AI INTELLIGENCE ══ -->
<div id="tab-ai" class="tab-panel <?=$tab==='ai'?'active':''?>">
<!-- AI Insights panel -->
<div class="ai-s-panel">
  <div class="ai-s-hdr">
    <div class="ai-s-hdr-icon">🤖</div>
    <div>
      <h3>AI Seller Intelligence</h3>
      <p><span class="ai-live-dot"></span>Demand forecast · Pricing · Sentiment · Growth recommendations</p>
    </div>
  </div>
  <div class="ai-s-body">
    <?php $recs = !empty($ai_recs) ? array_slice($ai_recs,0,3) : [
      ['icon'=>'📦','text'=>'<strong>Stock Alert:</strong> Add 10+ products to maximize your store visibility and get AI recommendations.'],
      ['icon'=>'📸','text'=>'<strong>Photo Tips:</strong> Products with 3+ photos get 60% more clicks. Upload clear, well-lit images.'],
      ['icon'=>'💡','text'=>'<strong>Trending:</strong> Handicraft and organic products are in high demand this season. Consider listing in these categories.'],
    ]; foreach($recs as $r):?>
    <div class="ai-s-sug"><span class="ai-s-sug-icon"><?=htmlspecialchars($r['icon']??'💡')?></span><span class="ai-s-sug-text"><?=$r['text']??''?></span></div>
    <?php endforeach;?>
  </div>
</div>
<!-- Demand Forecast -->
<?php if(!empty($ai_demand)):?>
<div class="sec-head"><h3>📈 AI Demand Forecast</h3></div>
<div class="demand-grid">
  <?php foreach(array_slice($ai_demand,0,6) as $d):?>
  <div class="demand-card">
    <?php if($d['trending']??false):?><span class="trending-badge">🔥 Trending</span><?php elseif($d['seasonal']??false):?><span class="trending-badge" style="background:linear-gradient(135deg,#2563EB,#06B6D4)">📅 Seasonal</span><?php endif;?>
    <div class="demand-card-label"><?=htmlspecialchars(ucfirst(str_replace('_',' ',$d['category']??'')))?></div>
    <div class="demand-card-score"><?=(int)($d['score']??0)?><span style="font-size:14px;color:var(--muted)">/100</span></div>
    <div class="demand-bar-wrap" style="margin-top:8px">
      <div class="demand-bar" style="width:<?=(int)($d['score']??0)?>%"></div>
    </div>
    <div class="demand-advice"><?=htmlspecialchars($d['advice']??'')?></div>
  </div>
  <?php endforeach;?>
</div>
<?php endif;?>
<!-- AI Chatbot -->
<div class="sc-chat-panel">
  <div class="sc-chat-hdr" onclick="toggleSChat()">
    <div style="width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0">🤖</div>
    <h4>AI Seller Assistant</h4>
    <span id="schatLabel" style="font-size:11px;color:rgba(255,255,255,.6)">Ask me anything →</span>
  </div>
  <div id="schatBody" style="display:none">
    <div class="sc-msgs" id="schatMessages"></div>
    <div class="sc-quick">
      <button class="sqb" onclick="sChat('Show demand forecast')">📈 Demand</button>
      <button class="sqb" onclick="sChat('Which products need pricing update?')">💰 Pricing</button>
      <button class="sqb" onclick="sChat('Show my revenue stats')">💵 Revenue</button>
      <button class="sqb" onclick="sChat('Review sentiment analysis')">⭐ Reviews</button>
      <button class="sqb" onclick="sChat('Generate product description')">✍️ Description</button>
    </div>
    <div class="sc-input-row">
      <input class="sc-input" id="schatInput" placeholder="Ask about pricing, demand, reviews, products…" maxlength="300" autocomplete="off">
      <button class="sc-send" onclick="sChat(document.getElementById('schatInput').value)">➤</button>
    </div>
  </div>
</div>
<!-- Product descriptions generator -->
<div class="form-card" style="margin-bottom:24px">
  <h3>✍️ AI Product Description Generator</h3>
  <p class="fc-sub">Enter product details — AI generates a professional description instantly.</p>
  <div class="form-grid">
    <div class="field"><label>Product Name *</label><input type="text" id="descName" placeholder="e.g. Hand-woven Cotton Saree"></div>
    <div class="field"><label>Category *</label>
      <select id="descCat"><?php foreach($cats as $v=>$l):?><option value="<?=$v?>"><?=$l?></option><?php endforeach;?></select>
    </div>
    <div class="field"><label>Price (₹)</label><input type="number" id="descPrice" placeholder="299"></div>
    <div class="field"><label>Material (optional)</label><input type="text" id="descMat" placeholder="e.g. pure cotton, organic silk"></div>
  </div>
  <button class="form-btn" onclick="generateDesc()">🤖 Generate Description</button>
  <div class="desc-result" id="descResult"></div>
</div>
</div><!-- /tab-ai -->
<!-- ══ TAB: STORE SETUP ══ -->
<div id="tab-store" class="tab-panel <?=$tab==='store'?'active':''?>">
<div class="form-card">
  <h3><?=$store?'Update Store':'Set Up Your Store'?></h3>
  <p class="fc-sub">This is how customers see your brand on SoulServe Shop.</p>
  <form method="POST" action="../api/seller_setup.php" enctype="multipart/form-data">
    <?=csrf_field()?>
    <div class="form-grid">
      <div class="field"><label>Store Name *</label><input type="text" name="store_name" value="<?=htmlspecialchars($store['store_name']??'')?>" required placeholder="e.g. Priya's Handlooms"></div>
      <div class="field"><label>Category *</label><select name="store_category" required><?php foreach($cats as $v=>$l):?><option value="<?=$v?>" <?=($store['store_category']??'')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></div>
      <div class="field full"><label>Tagline</label><input type="text" name="store_tagline" value="<?=htmlspecialchars($store['store_tagline']??'')?>" placeholder="A short catchy line about your store"></div>
      <div class="field full"><label>Store Description *</label><textarea name="store_description" required placeholder="Tell customers your story..."><?=htmlspecialchars($store['store_description']??'')?></textarea></div>
      <div class="field"><label>Store Logo</label><input type="file" name="store_logo" accept="image/*"><?php if(!empty($store['store_logo'])):?><div style="font-size:11px;color:var(--muted);margin-top:4px">Current: <?=basename($store['store_logo'])?></div><?php endif;?></div>
      <div class="field"><label>WhatsApp</label><input type="tel" name="whatsapp" value="<?=htmlspecialchars($store['whatsapp']??'')?>" placeholder="For order inquiries"></div>
      <div class="field"><label>Village / Town</label><input type="text" name="village" value="<?=htmlspecialchars($store['village']??'')?>" placeholder="Village or town"></div>
      <div class="field"><label>District</label><input type="text" name="district" value="<?=htmlspecialchars($store['district']??'')?>" placeholder="District"></div>
      <div class="field"><label>State</label><input type="text" name="state" value="<?=htmlspecialchars($store['state']??'')?>" placeholder="State"></div>
    </div>
    <button type="submit" class="form-btn">💾 <?=$store?'Update Store':'Create Store'?> →</button>
  </form>
</div>
</div><!-- /tab-store -->

<!-- ══ TAB: ADD PRODUCT ══ -->
<div id="tab-add_product" class="tab-panel <?=$tab==='add_product'?'active':''?>">
<?php if(!$store):?>
<div style="background:#fee2e2;border-radius:14px;padding:16px 20px;margin-bottom:20px;color:#991b1b;font-weight:700">⚠ Please set up your store before adding products.</div>
<?php else:?>
<div class="form-card">
  <h3>➕ New Product Listing</h3>
  <p class="fc-sub">Clear photos and honest descriptions sell better. AI can generate descriptions for you!</p>
  <form method="POST" action="../api/add_product.php" enctype="multipart/form-data">
    <?=csrf_field()?>
    <div class="form-grid">
      <div class="field full"><label>Product Name *</label><input type="text" name="name" required placeholder="e.g. Hand-woven Cotton Saree"></div>
      <div class="field full"><label>Description *</label><textarea name="description" required placeholder="Describe the product: material, size, how it's made…" id="productDescTA"></textarea></div>
      <div class="field"><label>Category *</label><select name="category" required><?php foreach($cats as $v=>$l):?><option value="<?=$v?>"><?=$l?></option><?php endforeach;?></select></div>
      <div class="field"><label>Selling Price (₹) *</label><input type="number" name="price" min="1" step="0.01" required placeholder="299"></div>
      <div class="field"><label>MRP (₹)</label><input type="number" name="mrp" min="1" step="0.01" placeholder="499"></div>
      <div class="field"><label>Stock *</label><input type="number" name="stock" min="0" required placeholder="How many pieces?"></div>
      <div class="field"><label>Weight (g)</label><input type="number" name="weight_grams" min="1" placeholder="500"></div>
      <div class="field"><label>Main Photo *</label><input type="file" name="image1" accept="image/*" required></div>
      <div class="field"><label>Photo 2</label><input type="file" name="image2" accept="image/*"></div>
      <div class="field"><label>Photo 3</label><input type="file" name="image3" accept="image/*"></div>
    </div>
    <button type="submit" class="form-btn">🚀 List Product →</button>
  </form>
</div>
<?php endif;?>
</div><!-- /tab-add_product -->

<!-- ══ TAB: MY PRODUCTS ══ -->
<div id="tab-products" class="tab-panel <?=$tab==='products'?'active':''?>">
<div class="sec-head"><h3>📦 My Products (<?=count($products)?>)</h3><button onclick="goTab('add_product')">➕ Add Product</button></div>
<?php if($low_stock>0):?>
<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:14px;padding:14px 18px;margin-bottom:18px;font-size:13px;font-weight:700;color:#92400e">⚠️ <?=$low_stock?> product<?=$low_stock>1?'s':''?> with 5 or fewer units in stock. Restock soon to avoid missed orders.</div>
<?php endif;?>
<?php if(empty($products)):?>
<div class="empty-state"><span class="emoji">📦</span><p>No products yet. <a href="#" onclick="goTab('add_product');return false;" style="color:var(--teal);font-weight:700">Add your first listing →</a></p></div>
<?php else:?>
<div class="prod-grid">
  <?php foreach($products as $p):
    $img  = !empty($p['image1']) ? image_url($p['image1']) : null;
    $pr   = $pricing[$p['id']] ?? null;
    $sent = $sentiment[$p['id']] ?? null;
  ?>
  <div class="prod-card">
    <?php if($img):?><img src="<?=htmlspecialchars($img)?>" class="prod-img" alt="<?=htmlspecialchars($p['name'])?>" loading="lazy">
    <?php else:?><div class="prod-img-ph">🛍️</div><?php endif;?>
    <div class="prod-body">
      <div class="prod-name" title="<?=htmlspecialchars($p['name'])?>"><?=htmlspecialchars($p['name'])?></div>
      <div class="prod-price">₹<?=number_format($p['price'],0)?><?php if($p['mrp']>$p['price']):?><span class="prod-mrp">₹<?=number_format($p['mrp'],0)?></span><?php endif;?></div>
      <div class="prod-meta">Stock: <?=(int)$p['stock']?> · Sold: <?=(int)$p['total_sold']?> · ⭐ <?=number_format($p['avg_rating'],1)?> (<?=(int)($p['rev_count']??0)?>)</div>
      <?php if((int)$p['stock']<=5):?><div style="font-size:11px;font-weight:700;color:#d97706;margin-bottom:6px">⚠️ Low stock — restock soon</div><?php endif;?>
      <?php if($pr && abs((float)$pr['suggested_price']-(float)$p['price'])>=5):?>
      <div class="prod-ai-row">💰 AI Price: ₹<?=number_format((float)$pr['suggested_price'],0)?> · <?=htmlspecialchars(substr($pr['action']??'',0,45))?></div>
      <?php endif;?>
      <?php if($sent):?>
      <div class="prod-ai-row">⭐ Sentiment: <?=$sent['score']?>% positive · <?=htmlspecialchars(substr($sent['summary']??'',0,50))?></div>
      <?php endif;?>
      <div style="margin-bottom:8px"><span class="pill <?=$p['is_active']?'delivered':'rejected'?>"><?=$p['is_active']?'Active':'Inactive'?></span></div>
      <div class="prod-actions">
        <form method="POST" action="../api/add_product.php" style="flex:1">
          <?=csrf_field()?><input type="hidden" name="toggle_id" value="<?=(int)$p['id']?>"><input type="hidden" name="active" value="<?=$p['is_active']?0:1?>">
          <button type="submit" class="btn-sm <?=$p['is_active']?'btn-warn':'btn-ok'?>" style="width:100%"><?=$p['is_active']?'Deactivate':'Activate'?></button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach;?>
</div>
<?php endif;?>
</div><!-- /tab-products -->
<!-- ══ TAB: ORDERS ══ -->
<div id="tab-orders" class="tab-panel <?=$tab==='orders'?'active':''?>">
<div class="sec-head"><h3>🛒 Manage Orders (<?=count($orders)?>)</h3></div>
<?php if(empty($orders)):?>
<div class="empty-state"><span class="emoji">🛒</span><p>No orders yet. Keep building your store!</p></div>
<?php else:?>
<div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06)">
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:700px">
    <thead><tr style="background:rgba(16,42,67,.03)">
      <?php foreach(['Order #','Items','Amount','Ship To','Status','Fraud','Action'] as $h):?>
      <th style="padding:11px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.06);text-align:left;white-space:nowrap"><?=$h?></th>
      <?php endforeach;?>
    </tr></thead>
    <tbody>
    <?php foreach($orders as $o):
      $ff = $fraud_flags[(int)$o['id']] ?? null;
    ?>
    <tr style="transition:.15s" onmouseover="this.style.background='rgba(0,109,119,.02)'" onmouseout="this.style.background=''">
      <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid rgba(16,42,67,.04)"><strong><?=htmlspecialchars($o['order_number'])?></strong><br><span style="font-size:11px;color:var(--muted)"><?=date('d M Y',strtotime($o['created_at']))?></span></td>
      <td style="padding:12px 14px;font-size:12px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.04);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($o['items']??'—')?></td>
      <td style="padding:12px 14px;font-size:13px;border-bottom:1px solid rgba(16,42,67,.04)"><strong>₹<?=number_format($o['total_amount'],0)?></strong></td>
      <td style="padding:12px 14px;font-size:12px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.04)"><?=htmlspecialchars($o['shipping_city'])?>, <?=htmlspecialchars($o['shipping_state'])?></td>
      <td style="padding:12px 14px;border-bottom:1px solid rgba(16,42,67,.04)"><span class="pill <?=htmlspecialchars($o['order_status'])?>"><?=ucfirst(str_replace('_',' ',$o['order_status']))?></span></td>
      <td style="padding:12px 14px;border-bottom:1px solid rgba(16,42,67,.04)">
        <?php if($ff && ($ff['risk']??'low')!=='low'):?>
        <span class="fraud-badge <?=$ff['risk']?>" title="<?=htmlspecialchars(implode(', ',$ff['flags']??[]))?>">⚠ <?=$ff['risk']?></span>
        <?php else:?><span class="fraud-badge low">✓</span><?php endif;?>
      </td>
      <td style="padding:12px 14px;border-bottom:1px solid rgba(16,42,67,.04)">
        <?php if($o['order_status']==='placed'):?>
        <form method="POST" action="../api/update_order_status.php" style="display:inline"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="status" value="confirmed"><button type="submit" class="btn-sm btn-ok">✓ Confirm</button></form>
        <?php elseif($o['order_status']==='confirmed'):?>
        <form method="POST" action="../api/update_order_status.php" style="display:inline"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="status" value="shipped"><input type="text" name="tracking_id" placeholder="Tracking ID" style="width:80px;padding:4px 8px;border-radius:6px;border:1px solid #e2ebe9;font-size:11px;margin-right:4px"><button type="submit" class="btn-sm btn-warn">🚚 Ship</button></form>
        <?php elseif($o['order_status']==='shipped'):?>
        <form method="POST" action="../api/update_order_status.php" style="display:inline"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="status" value="out_for_delivery"><button type="submit" class="btn-sm btn-warn">📦 Out</button></form>
        <?php else:?><span style="color:var(--muted);font-size:12px">—</span><?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
</div>
<?php endif;?>
</div><!-- /tab-orders -->

<!-- ══ TAB: BANK ══ -->
<div id="tab-bank" class="tab-panel <?=$tab==='bank'?'active':''?>">
<div class="form-card">
  <h3>🏦 Bank &amp; Payment Details</h3>
  <p class="fc-sub">Used for settlements. Kept secure and never shared.</p>
  <form method="POST" action="../api/seller_setup.php" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="bank_only" value="1">
    <div class="form-grid">
      <div class="field"><label>UPI ID</label><input type="text" name="upi_id" value="<?=htmlspecialchars($store['upi_id']??'')?>" placeholder="yourname@upi"></div>
      <div class="field"><label>Bank Name</label><input type="text" name="bank_name" value="<?=htmlspecialchars($store['bank_name']??'')?>" placeholder="e.g. State Bank of India"></div>
      <div class="field"><label>Account Holder Name</label><input type="text" name="bank_holder_name" value="<?=htmlspecialchars($store['bank_holder_name']??'')?>" placeholder="Name as on passbook"></div>
      <div class="field"><label>Account Number</label><input type="text" name="bank_account" value="<?=htmlspecialchars($store['bank_account']??'')?>" placeholder="Account number"></div>
      <div class="field"><label>IFSC Code</label><input type="text" name="bank_ifsc" value="<?=htmlspecialchars($store['bank_ifsc']??'')?>" placeholder="SBIN0001234"></div>
    </div>
    <div style="background:#fef3c7;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#92400e">🔒 Your banking details are encrypted. Never share your OTP or password with anyone.</div>
    <button type="submit" class="form-btn">💾 Save Payment Details →</button>
  </form>
</div>
</div><!-- /tab-bank -->

</div><!-- .page -->
</main>
</div><!-- .app -->

<div id="dashToast"></div>
<script src="../js/dashboard.js"></script>
<script defer src="../js/ai_chat.js"></script>
<script>
/* Tab switching */
function goTab(t){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab===t));
  const p=document.getElementById('tab-'+t);if(p)p.classList.add('active');
  history.replaceState(null,'','?tab='+t);
}

/* AI Seller Chatbot */
let scOpen=false;
function toggleSChat(){
  scOpen=!scOpen;
  document.getElementById('schatBody').style.display=scOpen?'block':'none';
  document.getElementById('schatLabel').textContent=scOpen?'Click to minimize':'Ask me anything →';
  if(scOpen&&document.getElementById('schatMessages').children.length===0){
    addSMsg('🏪 Hi <?=addslashes(htmlspecialchars($user['name']))?> ! I\'m your AI Seller Assistant.<br>Ask me about demand forecasting, pricing, reviews, or generating product descriptions.','bot');
    document.getElementById('schatInput').focus();
  }
}
function addSMsg(text,role){
  const w=document.getElementById('schatMessages');
  const d=document.createElement('div');d.className='smsg '+role;
  d.innerHTML=`<div class="sico">${role==='bot'?'🤖':'👤'}</div><div class="sbub">${text}</div>`;
  w.appendChild(d);w.scrollTop=w.scrollHeight;
}
function sChat(q){
  q=q.trim();if(!q)return;
  document.getElementById('schatInput').value='';
  if(!scOpen)toggleSChat();
  addSMsg(q,'user');
  document.getElementById('schatBody').querySelector('.sc-quick').style.display='none';
  const w=document.getElementById('schatMessages');
  const td=document.createElement('div');td.className='smsg bot';td.id='styping';
  td.innerHTML='<div class="sico">🤖</div><div class="typing-dots"><span></span><span></span><span></span></div>';
  w.appendChild(td);w.scrollTop=w.scrollHeight;
  fetch('../api/ai_assistant.php',{method:'POST',body:new URLSearchParams({message:q,context:'seller'})})
    .then(r=>r.json()).then(d=>{document.getElementById('styping')?.remove();addSMsg(d.reply||'Could not process.','bot');})
    .catch(()=>{document.getElementById('styping')?.remove();addSMsg('Connection error.','bot');});
}
document.getElementById('schatInput').addEventListener('keydown',e=>{if(e.key==='Enter')sChat(e.target.value);});

/* AI Product Description Generator */
function generateDesc(){
  const name  = document.getElementById('descName').value.trim();
  const cat   = document.getElementById('descCat').value;
  const price = parseFloat(document.getElementById('descPrice').value)||0;
  const mat   = document.getElementById('descMat').value.trim();
  if(!name){alert('Please enter a product name.');return;}
  const r=document.getElementById('descResult');
  r.style.display='block';r.textContent='🤖 Generating…';
  fetch('../api/ai_assistant.php',{method:'POST',body:new URLSearchParams({
    message:`Generate a product description for: Name="${name}", Category="${cat}", Price=₹${price}, Material="${mat}"`,
    context:'seller'
  })}).then(r2=>r2.json()).then(d=>{
    r.innerHTML=d.reply||'Could not generate description.';
    // Also fill the add-product form if visible
    const ta=document.getElementById('productDescTA');
    if(ta && !ta.value) ta.value=r.innerText.replace(/<[^>]+>/g,'').trim();
  }).catch(()=>{r.textContent='Generation failed. Please try again.';});
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
