<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
require_once __DIR__ . '/../api/ai_engine.php';
/* ── Role-based access guard ── */
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'seller') {
    $chk = $conn->prepare("SELECT role FROM register WHERE email=? AND verified=1");
    $chk->bind_param("s",$_SESSION['user_email']); $chk->execute();
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
function sq_i($c,$s):int{ try{$r=$c->query($s);return($r&&$row=$r->fetch_assoc())?(int)$row['c']:0;}catch(Throwable $e){return 0;} }
function sq_f($c,$s):float{ try{$r=$c->query($s);return($r&&$row=$r->fetch_assoc())?(float)$row['r']:0.0;}catch(Throwable $e){return 0.0;} }
$uq=$conn->prepare("SELECT name FROM register WHERE email=? AND role='seller' AND verified=1");
$uq->bind_param("s",$email); $uq->execute();
$user=$uq->get_result()->fetch_assoc();
if(!$user){ header("Location: ../auth/login.php"); exit; }
$store=null;
try{$sq2=$conn->prepare("SELECT * FROM seller_stores WHERE seller_email=?");$sq2->bind_param("s",$email);$sq2->execute();$store=$sq2->get_result()->fetch_assoc();}catch(Throwable $e){}
$total_products=$total_orders=$pending_orders=$low_stock=0; $total_revenue=0.0;
if($store){
  $total_products=sq_i($conn,"SELECT COUNT(*) c FROM products WHERE seller_email='$me' AND is_active=1");
  $total_orders  =sq_i($conn,"SELECT COUNT(*) c FROM orders WHERE seller_email='$me'");
  $total_revenue =sq_f($conn,"SELECT COALESCE(SUM(total_amount),0) r FROM orders WHERE seller_email='$me' AND order_status NOT IN ('cancelled','returned')");
  $pending_orders=sq_i($conn,"SELECT COUNT(*) c FROM orders WHERE seller_email='$me' AND order_status='placed'");
  $low_stock     =sq_i($conn,"SELECT COUNT(*) c FROM products WHERE seller_email='$me' AND stock<=5 AND is_active=1");
}
$products=[];
try{$pq=$conn->prepare("SELECT p.*,(SELECT COUNT(*) FROM product_reviews WHERE product_id=p.id) rev_count FROM products p WHERE p.seller_email=? ORDER BY p.created_at DESC");$pq->bind_param("s",$email);$pq->execute();$products=$pq->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}
$orders=[];
try{$oq=$conn->prepare("SELECT o.*,GROUP_CONCAT(oi.product_name SEPARATOR ', ') AS items FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE o.seller_email=? GROUP BY o.id ORDER BY o.created_at DESC LIMIT 50");$oq->bind_param("s",$email);$oq->execute();$orders=$oq->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}
$weekly_rev=[]; $weekly_labels=[];
try{for($i=6;$i>=0;$i--){$from=date('Y-m-d',strtotime("-$i days"));$rev=(float)$conn->query("SELECT COALESCE(SUM(total_amount),0) r FROM orders WHERE seller_email='$me' AND DATE(created_at)='$from' AND order_status NOT IN ('cancelled','returned')")->fetch_assoc()['r'];$weekly_rev[]=$rev;$weekly_labels[]=date('D',strtotime($from));}}catch(Throwable $e){}
$ai=adhaar_ai();
$ai_recs=$ai_demand=[];
try{
  $ai_recs    = ai_cached("seller_recs_{$email}",    300, fn()=> $ai->getSellerRecommendations($email) ?: []);
  $ai_demand  = ai_cached("seller_demand_{$email}",  600, fn()=> $ai->sellerDemandForecast($email) ?: []);
}catch(Throwable $e){}
$fraud_flags=[]; $sentiment=[]; $pricing=[];
foreach(array_slice($orders,0,10) as $o){
  try{
    $oid=(int)$o['id'];
    $f = ai_cached("seller_fraud_{$oid}", 600, fn()=> $ai->detectOrderFraud($oid));
    if(($f['risk']??'low')!=='low') $fraud_flags[$o['id']]=$f;
  }catch(Throwable $e){}
}
foreach(array_slice($products,0,5) as $p){
  try{
    $pid=(int)$p['id'];
    if((int)($p['rev_count']??0)>0)
      $sentiment[$p['id']] = ai_cached("seller_sent_{$pid}", 600, fn()=> $ai->analyzeReviewSentiment($pid));
  }catch(Throwable $e){}
}
foreach(array_slice($products,0,5) as $p){
  try{
    $pid=(int)$p['id'];
    $pricing[$p['id']] = ai_cached("seller_price_{$pid}", 300, fn()=> $ai->suggestPricing($pid));
  }catch(Throwable $e){}
}
$tab=$_GET['tab']??'overview'; $success=$_GET['success']??''; $err=$_GET['err']??'';
$cats=['handicraft'=>'Handicraft','textile'=>'Textile','food_product'=>'Food Product','jewelry'=>'Jewelry','art'=>'Art','pottery'=>'Pottery','organic'=>'Organic','other'=>'Other'];
$cat_icons=['handicraft'=>'🎨','textile'=>'🧵','food_product'=>'🍯','jewelry'=>'💍','art'=>'🖼️','pottery'=>'🏺','organic'=>'🌿','other'=>'📦'];
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" defer></script>
<style>
/* ══ SELLER DASHBOARD DESIGN SYSTEM ══ */
:root{
  --s1:#006D77;--s2:#2E8B57;--s3:#FF8A00;--s4:#F72585;--s5:#2563EB;
  --sgr:linear-gradient(135deg,#006D77,#2E8B57);
  --shr:linear-gradient(135deg,#102A43 0%,#006D77 55%,#2E8B57 100%);
  --card-r:20px;
}

/* ── Bottom navigation for mobile ── */
.bottom-nav{
  display:none;
  position:fixed;
  bottom:0;left:0;right:0;
  background:#fff;
  border-top:1px solid rgba(16,42,67,.1);
  z-index:600;
  padding:0 4px env(safe-area-inset-bottom,0);
  box-shadow:0 -4px 20px rgba(16,42,67,.1);
}
.bn-items{display:flex;align-items:stretch;height:60px}
.bn-item{
  flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:3px;cursor:pointer;background:none;border:none;font-family:inherit;
  color:var(--muted);font-size:9px;font-weight:700;text-transform:uppercase;
  letter-spacing:.3px;transition:.2s;-webkit-tap-highlight-color:transparent;
  text-decoration:none;padding:6px 2px;
}
.bn-item .bn-icon{font-size:20px;line-height:1;transition:.2s}
.bn-item.active{color:var(--s1)}
.bn-item.active .bn-icon{transform:scale(1.15)}
.bn-badge{
  position:absolute;top:-2px;right:-4px;
  background:#ef4444;color:#fff;font-size:8px;font-weight:900;
  width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  border:2px solid #fff;
}
.bn-icon-wrap{position:relative;display:inline-block}

/* ── Welcome band ── */
.s-welcome{
  background:var(--shr);border-radius:24px;padding:24px 28px;
  color:#fff;margin-bottom:20px;position:relative;overflow:hidden;
  display:flex;justify-content:space-between;align-items:center;
  flex-wrap:wrap;gap:12px;
  box-shadow:0 16px 48px rgba(0,109,119,.22);
  animation:fadeUp .5s ease;
}
.s-welcome::before{content:'';position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none}
.sw-title{font-size:20px;font-weight:800;margin-bottom:3px}
.sw-sub{font-size:13px;opacity:.75;line-height:1.5}
.sw-actions{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1}
.sw-btn{
  padding:9px 18px;border-radius:20px;font-size:13px;font-weight:700;
  cursor:pointer;text-decoration:none;border:none;transition:.2s;white-space:nowrap;
  -webkit-tap-highlight-color:transparent;
}
.sw-btn.white{background:#fff;color:var(--s1)}.sw-btn.white:hover{background:#e8fdf5}
.sw-btn.glass{background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.25)}
.sw-btn.glass:hover{background:rgba(255,255,255,.22)}

/* ── KPI cards ── */
.s-kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}
.s-kpi{
  background:#fff;border-radius:var(--card-r);padding:18px 16px;
  box-shadow:0 4px 20px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);
  transition:.3s;position:relative;overflow:hidden;cursor:default;
}
.s-kpi:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(16,42,67,.12)}
.s-kpi::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 var(--card-r) var(--card-r);opacity:0;transition:.3s}
.s-kpi:hover::after{opacity:1}
.s-kpi.k1::after{background:var(--sgr)}.s-kpi.k2::after{background:linear-gradient(135deg,var(--s3),var(--s4))}
.s-kpi.k3::after{background:linear-gradient(135deg,var(--s5),#7B2CBF)}.s-kpi.k4::after{background:linear-gradient(135deg,#dc2626,#f87171)}
.s-kpi.k5::after{background:linear-gradient(135deg,var(--s2),var(--s1))}
.s-kpi-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:10px}
.s-kpi-val{font-size:26px;font-weight:900;color:var(--navy);line-height:1;margin-bottom:3px}
.s-kpi-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}

/* ── Section header ── */
.s-sec-head{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:16px;flex-wrap:wrap;gap:8px;
}
.s-sec-head h3{font-size:15px;font-weight:800;color:var(--navy);display:flex;align-items:center;gap:8px}
.s-sec-btn{
  font-size:12px;font-weight:700;color:var(--teal);text-decoration:none;
  padding:6px 14px;border-radius:20px;background:rgba(0,109,119,.08);
  border:none;cursor:pointer;transition:.2s;white-space:nowrap;font-family:inherit;
}
.s-sec-btn:hover{background:rgba(0,109,119,.15)}

/* ── No store banner ── */
.no-store-card{
  background:linear-gradient(135deg,rgba(255,138,0,.08),rgba(247,37,133,.05));
  border:2px dashed #f59e0b;border-radius:var(--card-r);
  padding:28px 24px;text-align:center;margin-bottom:20px;
}
.no-store-card h3{font-size:18px;font-weight:800;color:var(--navy);margin-bottom:8px}
.no-store-card p{font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.6}

/* ── AI panel ── */
.s-ai-panel{
  background:linear-gradient(135deg,#0a1f30,#0d3858 40%,#006d77 100%);
  border-radius:var(--card-r);overflow:hidden;margin-bottom:20px;
  box-shadow:0 12px 36px rgba(0,109,119,.2);
}
.s-ai-hdr{padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.1)}
.s-ai-hdr-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.s-ai-hdr-text h4{font-size:14px;font-weight:800;color:#fff;margin-bottom:2px}
.s-ai-hdr-text p{font-size:11px;color:rgba(255,255,255,.55)}
.ai-live-dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:#10b981;animation:livP 1.6s ease infinite;margin-right:4px}
@keyframes livP{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}
.s-ai-body{padding:14px 20px;display:flex;flex-direction:column;gap:8px}
.s-ai-sug{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-radius:12px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.08);transition:.2s}
.s-ai-sug:hover{background:rgba(255,255,255,.11)}
.s-ai-sug-icon{font-size:17px;flex-shrink:0;margin-top:1px}
.s-ai-sug-text{font-size:13px;color:rgba(255,255,255,.85);line-height:1.6}.s-ai-sug-text strong{color:#fff}

/* ── Chart card ── */
.chart-wrap{background:#fff;border-radius:var(--card-r);padding:20px;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);margin-bottom:20px}
.chart-wrap h4{font-size:14px;font-weight:800;color:var(--navy);margin-bottom:4px}
.chart-wrap p{font-size:12px;color:var(--muted);margin-bottom:16px}
.chart-canvas-wrap{height:180px;position:relative}

/* ── Products grid ── */
.s-prod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
.s-prod-card{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 14px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s}
.s-prod-card:hover{transform:translateY(-4px);box-shadow:0 12px 28px rgba(16,42,67,.1)}
.s-prod-img{width:100%;height:140px;object-fit:cover;background:linear-gradient(135deg,#edf5f2,#eef2ff);display:block}
.s-prod-img-ph{width:100%;height:140px;display:flex;align-items:center;justify-content:center;font-size:40px;background:linear-gradient(135deg,#edf5f2,#eef2ff)}
.s-prod-body{padding:12px 14px 14px}
.s-prod-name{font-size:13px;font-weight:800;color:var(--navy);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.s-prod-price{font-size:15px;font-weight:900;color:var(--s1);margin-bottom:2px}
.s-prod-mrp{font-size:11px;color:var(--muted);text-decoration:line-through;margin-left:5px}
.s-prod-meta{font-size:11px;color:var(--muted);margin-bottom:7px;line-height:1.5}
.s-prod-ai{background:rgba(0,109,119,.05);border-radius:7px;padding:6px 9px;font-size:10px;color:var(--navy);margin-bottom:7px;line-height:1.5}
.s-prod-footer{display:flex;gap:6px;align-items:center}
.pill-active{background:#d1fae5;color:#065f46;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px}
.pill-inactive{background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px}

/* ── Orders list (mobile-friendly cards) ── */
.s-order-list{display:flex;flex-direction:column;gap:12px;margin-bottom:20px}
.s-order-card{
  background:#fff;border-radius:16px;padding:16px 18px;
  box-shadow:0 4px 14px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);
  transition:.2s;
}
.s-order-card:hover{box-shadow:0 8px 24px rgba(16,42,67,.1)}
.s-order-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px}
.s-order-num{font-size:13px;font-weight:800;color:var(--navy);display:flex;align-items:center;gap:6px}
.s-order-date{font-size:11px;color:var(--muted);margin-top:2px}
.s-order-badges{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.s-order-items{font-size:12px;color:var(--muted);margin-bottom:8px;line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.s-order-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.s-order-amount{font-size:16px;font-weight:900;color:var(--navy)}
.s-order-ship{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:4px}
.s-order-actions{display:flex;gap:8px;flex-wrap:wrap}
.s-order-action-btn{
  flex:1;min-width:100px;padding:9px 14px;border:none;border-radius:10px;
  font-size:12px;font-weight:700;cursor:pointer;transition:.2s;font-family:inherit;
  display:flex;align-items:center;justify-content:center;gap:5px;
  -webkit-tap-highlight-color:transparent;
}
.s-order-action-btn.confirm{background:#d1fae5;color:#065f46}.s-order-action-btn.confirm:hover{background:#a7f3d0}
.s-order-action-btn.ship{background:#dbeafe;color:#1e40af}.s-order-action-btn.ship:hover{background:#bfdbfe}
.s-order-action-btn.deliver{background:#ede9fe;color:#5b21b6}.s-order-action-btn.deliver:hover{background:#ddd6fe}
.tracking-input{flex:1;min-width:100px;padding:8px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:12px;outline:none;font-family:inherit}
.tracking-input:focus{border-color:var(--teal)}

/* ── Status pills ── */
.pill{display:inline-block;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap;letter-spacing:.3px}
.pill.placed,.pill.pending{background:#fef3c7;color:#92400e}
.pill.confirmed{background:#dbeafe;color:#1e40af}
.pill.shipped,.pill.processing{background:#ede9fe;color:#5b21b6}
.pill.out_for_delivery{background:#fce7f3;color:#9d174d}
.pill.delivered{background:#d1fae5;color:#065f46}
.pill.cancelled,.pill.returned{background:#fee2e2;color:#991b1b}

/* ── Fraud badge ── */
.fraud-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px}
.fraud-badge.high{background:#fee2e2;color:#991b1b}.fraud-badge.medium{background:#fef3c7;color:#92400e}.fraud-badge.low{background:#d1fae5;color:#065f46}

/* ── Form styles ── */
.s-form-card{background:#fff;border-radius:var(--card-r);box-shadow:0 4px 16px rgba(16,42,67,.07);padding:24px;margin-bottom:20px}
.s-form-card h3{font-size:17px;font-weight:800;margin-bottom:4px;color:var(--navy)}
.s-form-sub{font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.6}
.s-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.s-form-grid.one{grid-template-columns:1fr}
.sf{margin-bottom:0}
.sf label{display:block;font-size:11px;font-weight:700;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.sf input,.sf select,.sf textarea{
  width:100%;padding:11px 14px;border:1.5px solid var(--border);
  border-radius:10px;font-size:14px;font-family:inherit;color:var(--navy);
  transition:.2s;outline:none;background:#fafbfa;
  -webkit-appearance:none;
}
.sf input:focus,.sf select:focus,.sf textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(0,109,119,.1);background:#fff}
.sf textarea{resize:vertical;min-height:90px}
.sf.full{grid-column:1/-1}
.sf input[type=file]{background:#fafbfa;cursor:pointer}
.s-form-btn{
  padding:13px 24px;border:none;border-radius:12px;font-size:14px;font-weight:700;
  cursor:pointer;transition:.25s;background:var(--sgr);color:#fff;
  box-shadow:0 4px 14px rgba(0,109,119,.25);font-family:inherit;
  -webkit-tap-highlight-color:transparent;
}
.s-form-btn:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(0,109,119,.35)}
.s-form-btn:active{transform:none}

/* ── Demand cards ── */
.demand-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.demand-card{background:#fff;border-radius:14px;padding:16px;box-shadow:0 4px 14px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);position:relative;overflow:hidden;transition:.2s}
.demand-card:hover{transform:translateY(-3px)}
.demand-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.demand-score{font-size:26px;font-weight:900;color:var(--navy);line-height:1;margin-bottom:6px}
.demand-bar-wrap{height:4px;background:rgba(16,42,67,.08);border-radius:4px;overflow:hidden;margin-bottom:6px}
.demand-bar{height:100%;background:var(--sgr);border-radius:4px;transition:width 1.2s cubic-bezier(.22,1,.36,1)}
.demand-advice{font-size:10px;color:var(--muted);line-height:1.5}
.trend-badge{position:absolute;top:10px;right:10px;font-size:9px;font-weight:800;padding:2px 8px;border-radius:20px;text-transform:uppercase}
.trend-badge.hot{background:linear-gradient(135deg,#FF8A00,#F72585);color:#fff}
.trend-badge.season{background:linear-gradient(135deg,#2563EB,#06B6D4);color:#fff}

/* ── AI Chatbot ── */
.s-chat-wrap{background:#fff;border-radius:var(--card-r);border:1px solid rgba(16,42,67,.06);box-shadow:0 4px 16px rgba(16,42,67,.07);overflow:hidden;margin-bottom:20px}
.s-chat-hdr{background:var(--shr);padding:14px 18px;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent}
.s-chat-hdr h4{font-size:14px;font-weight:800;color:#fff;margin:0;flex:1}
.s-chat-hdr-right{font-size:11px;color:rgba(255,255,255,.6);display:flex;align-items:center;gap:6px}
.s-chat-body{background:#f9fbfa}
.s-chat-msgs{height:240px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:9px;scroll-behavior:smooth}
.s-cmsg{display:flex;gap:7px;align-items:flex-end;animation:fadeUp .2s ease}
.s-cmsg.bot{flex-direction:row}.s-cmsg.user{flex-direction:row-reverse}
.s-cbub{max-width:82%;padding:10px 14px;border-radius:16px;font-size:13px;line-height:1.6}
.s-cmsg.bot .s-cbub{background:#fff;color:var(--navy);border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px}
.s-cmsg.user .s-cbub{background:var(--sgr);color:#fff;border-radius:16px 4px 16px 16px}
.s-cico{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.s-cmsg.bot .s-cico{background:var(--sgr);color:#fff}.s-cmsg.user .s-cico{background:#e2ebe9;color:var(--navy)}
.s-chat-quick{padding:8px 14px 10px;display:flex;gap:5px;flex-wrap:wrap;border-top:1px solid rgba(16,42,67,.06)}
.s-cqb{padding:5px 11px;border-radius:20px;border:1.5px solid rgba(0,109,119,.2);background:#fff;font-size:11px;font-weight:600;color:var(--s1);cursor:pointer;transition:.2s;font-family:inherit;-webkit-tap-highlight-color:transparent}
.s-cqb:hover{background:var(--s1);color:#fff}
.s-chat-input-row{display:flex;border-top:1px solid rgba(16,42,67,.08)}
.s-chat-input{flex:1;padding:12px 15px;border:none;outline:none;font-size:13px;font-family:inherit;background:#fff;color:var(--navy);font-size:16px}
.s-chat-send{padding:0 16px;background:var(--sgr);color:#fff;border:none;cursor:pointer;font-size:14px;transition:.2s;-webkit-tap-highlight-color:transparent}
.typing-dots{display:flex;gap:4px;align-items:center;padding:10px 14px;background:#fff;border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px;width:fit-content}
.typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:tdot 1.2s infinite}
.typing-dots span:nth-child(2){animation-delay:.2s}.typing-dots span:nth-child(3){animation-delay:.4s}
@keyframes tdot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}

/* ── AI Description generator ── */
.desc-result{background:linear-gradient(135deg,rgba(0,109,119,.06),rgba(46,139,87,.04));border:1.5px solid rgba(0,109,119,.2);border-radius:12px;padding:14px;font-size:13px;color:var(--navy);line-height:1.7;margin-top:14px;display:none}

/* ── Empty state ── */
.s-empty{background:#f9fbfa;border:1.5px dashed rgba(16,42,67,.12);border-radius:16px;padding:40px 20px;text-align:center;color:var(--muted)}
.s-empty .emoji{font-size:40px;display:block;margin-bottom:12px}
.s-empty p{font-size:14px;line-height:1.6}

/* ── Alert banners ── */
.s-alert-warn{background:#fef3c7;border:1.5px solid #fde68a;border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;color:#92400e;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.s-alert-err{background:#fee2e2;border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;color:#991b1b;margin-bottom:16px}
.s-success{background:#d1fae5;border:1px solid #6ee7b7;border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;color:#065f46;margin-bottom:16px}

/* ── Bank details card ── */
.bank-card{background:linear-gradient(135deg,rgba(37,99,235,.04),rgba(37,99,235,.02));border:1.5px solid rgba(37,99,235,.15);border-radius:var(--card-r);padding:20px;margin-bottom:16px}
.bank-info-note{background:#fef3c7;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px}

/* ── Low stock badge in products ── */
.s-low-stock{font-size:10px;font-weight:700;color:#d97706;background:#fef3c7;padding:2px 8px;border-radius:20px;display:inline-block;margin-bottom:6px}

/* ── Responsive ── */
@media(max-width:1200px){.s-kpi-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1024px){.s-prod-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .s-kpi-row{grid-template-columns:repeat(3,1fr);gap:12px}
  .s-prod-grid{grid-template-columns:repeat(2,1fr)}
  .s-form-grid{grid-template-columns:1fr 1fr}
  .demand-grid{grid-template-columns:repeat(3,1fr)}
  .s-welcome{padding:20px 22px;border-radius:18px}
}
@media(max-width:700px){
  /* Bottom nav visible */
  .bottom-nav{display:block}
  /* Sidebar hidden by default on mobile */
  .sidebar{transform:translateX(-100%);z-index:500;width:260px}
  .sidebar.open,.sidebar.show{transform:translateX(0)}
  .main{margin-left:0}
  .mobile-topbar{display:flex !important}
  .topbar{display:none}
  /* Main page padding + bottom nav clearance */
  .page{padding:14px;padding-bottom:76px}
  .s-kpi-row{grid-template-columns:repeat(2,1fr);gap:10px}
  .s-prod-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .demand-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .s-form-grid{grid-template-columns:1fr}
  .sw-title{font-size:17px}
  .s-welcome{padding:16px 18px;border-radius:16px;gap:10px}
  .sw-actions{gap:6px}
  .sw-btn{padding:8px 14px;font-size:12px}
  .chart-canvas-wrap{height:150px}
  .s-order-card{padding:14px 16px}
  .s-chat-msgs{height:200px}
}
@media(max-width:480px){
  .s-kpi-row{grid-template-columns:1fr 1fr;gap:8px}
  .s-kpi-val{font-size:22px}
  .s-kpi-icon{width:36px;height:36px;font-size:17px;margin-bottom:8px}
  .s-prod-grid{grid-template-columns:1fr 1fr;gap:8px}
  .s-prod-img,.s-prod-img-ph{height:120px}
  .s-prod-body{padding:10px 11px}
  .s-prod-name{font-size:12px}
  .s-prod-price{font-size:14px}
  .demand-grid{grid-template-columns:1fr 1fr;gap:8px}
  .s-form-card{padding:16px}
  .s-form-btn{width:100%;justify-content:center}
  .s-welcome{border-radius:14px;padding:14px}
  .sw-title{font-size:15px}.sw-sub{font-size:12px}
  .s-order-actions{flex-direction:column}
  .s-order-action-btn{flex:none;width:100%}
  .page{padding:12px;padding-bottom:76px}
  .s-kpi-label{font-size:9px}
  .s-chat-msgs{height:180px}
  .s-chat-input{font-size:16px}
}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
</style>
</head>
<body>
<!-- ══ MOBILE TOPBAR ══ -->
<div class="mobile-topbar">
  <div style="padding:0 16px;height:56px;display:flex;align-items:center;justify-content:space-between">
    <img src="../assets/logo.png" alt="SoulServe" style="height:28px;object-fit:contain" loading="eager">
    <div style="display:flex;align-items:center;gap:8px">
      <?php if($pending_orders>0):?>
      <div style="position:relative;cursor:pointer" onclick="switchTab('orders')">
        <span style="font-size:20px">🛒</span>
        <span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:8px;font-weight:900;width:14px;height:14px;border-radius:50%;display:flex;align-items:center;justify-content:center"><?=$pending_orders?></span>
      </div>
      <?php endif;?>
      <button class="hamburger" id="hamburger" aria-label="Menu"
        style="display:flex;flex-direction:column;gap:5px;cursor:pointer;padding:8px;background:none;border:none;-webkit-tap-highlight-color:transparent">
        <span style="display:block;width:20px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
        <span style="display:block;width:20px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
        <span style="display:block;width:20px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
      </button>
    </div>
  </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ══ BOTTOM NAV (mobile) ══ -->
<nav class="bottom-nav" id="bottomNav">
  <div class="bn-items">
    <button class="bn-item <?=$tab==='overview'?'active':''?>" onclick="switchTab('overview')">
      <div class="bn-icon-wrap"><span class="bn-icon">📊</span></div>
      <span>Overview</span>
    </button>
    <button class="bn-item <?=$tab==='products'?'active':''?>" onclick="switchTab('products')">
      <div class="bn-icon-wrap">
        <span class="bn-icon">📦</span>
        <?php if(count($products)>0):?><span class="bn-badge"><?=min(99,count($products))?></span><?php endif;?>
      </div>
      <span>Products</span>
    </button>
    <button class="bn-item <?=$tab==='add_product'?'active':''?>" onclick="switchTab('add_product')" style="color:var(--s1)">
      <div class="bn-icon-wrap" style="width:44px;height:44px;border-radius:50%;background:var(--sgr);display:flex;align-items:center;justify-content:center;margin-top:-8px;box-shadow:0 4px 14px rgba(0,109,119,.3)">
        <span style="font-size:22px;color:#fff">+</span>
      </div>
      <span>Add</span>
    </button>
    <button class="bn-item <?=$tab==='orders'?'active':''?>" onclick="switchTab('orders')">
      <div class="bn-icon-wrap">
        <span class="bn-icon">🛒</span>
        <?php if($pending_orders>0):?><span class="bn-badge"><?=$pending_orders?></span><?php endif;?>
      </div>
      <span>Orders</span>
    </button>
    <button class="bn-item <?=$tab==='ai'?'active':''?>" onclick="switchTab('ai')">
      <div class="bn-icon-wrap"><span class="bn-icon">🤖</span></div>
      <span>AI</span>
    </button>
  </div>
</nav>

<div class="app">
<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="sidebar-logo-mark"><img src="../assets/logo.png" alt="SoulServe" style="width:28px;height:28px;object-fit:contain;filter:brightness(0)invert(1)"></div>
    <div class="sidebar-logo-text"><strong>SoulServe</strong><span>Seller Portal</span></div>
  </div>
  <!-- Seller profile chip -->
  <div style="margin:8px 10px 4px;padding:12px 14px;background:rgba(255,255,255,.06);border-radius:12px;border:1px solid rgba(255,255,255,.08)">
    <div style="width:34px;height:34px;border-radius:50%;background:var(--gradient-teal);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:800;margin-bottom:7px"><?=strtoupper(substr($user['name'],0,1))?></div>
    <div style="font-size:12px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($user['name'])?></div>
    <div style="font-size:10px;color:rgba(255,255,255,.45);margin-top:2px;display:flex;align-items:center;gap:4px">
      🏪 Seller <?php if($store):?><span style="background:rgba(16,185,129,.2);color:#10b981;padding:1px 7px;border-radius:20px;font-size:9px;font-weight:700">ACTIVE</span><?php endif;?>
    </div>
  </div>

  <div class="nav-sec">Dashboard</div>
  <button class="nav-btn <?=$tab==='overview'?'active':''?>" data-tab="overview" onclick="switchTab('overview')"><span class="nav-icon">📊</span> Overview</button>
  <button class="nav-btn <?=$tab==='ai'?'active':''?>" data-tab="ai" onclick="switchTab('ai')"><span class="nav-icon">🤖</span> AI Intelligence</button>

  <div class="nav-sec">Products</div>
  <button class="nav-btn <?=$tab==='store'?'active':''?>" data-tab="store" onclick="switchTab('store')"><span class="nav-icon">🏬</span> My Store</button>
  <button class="nav-btn <?=$tab==='add_product'?'active':''?>" data-tab="add_product" onclick="switchTab('add_product')"><span class="nav-icon">➕</span> Add Product</button>
  <button class="nav-btn <?=$tab==='products'?'active':''?>" data-tab="products" onclick="switchTab('products')">
    <span class="nav-icon">📦</span> My Products
    <?php if(count($products)>0):?><span class="nav-badge green"><?=count($products)?></span><?php endif;?>
  </button>
  <?php if($low_stock>0):?>
  <button class="nav-btn" onclick="switchTab('products')" style="color:#d97706;font-size:12px">
    <span class="nav-icon">⚠️</span> Low Stock <span class="nav-badge orange"><?=$low_stock?></span>
  </button>
  <?php endif;?>

  <div class="nav-sec">Business</div>
  <button class="nav-btn <?=$tab==='orders'?'active':''?>" data-tab="orders" onclick="switchTab('orders')">
    <span class="nav-icon">🛒</span> Orders
    <?php if($pending_orders>0):?><span class="nav-badge"><?=$pending_orders?></span><?php endif;?>
  </button>
  <button class="nav-btn <?=$tab==='analytics'?'active':''?>" data-tab="analytics" onclick="switchTab('analytics')"><span class="nav-icon">📈</span> Analytics</button>
  <button class="nav-btn <?=$tab==='bank'?'active':''?>" data-tab="bank" onclick="switchTab('bank')"><span class="nav-icon">🏦</span> Bank Details</button>

  <div class="nav-sec">Community</div>
  <a href="../shop/shop.php" class="nav-btn"><span class="nav-icon">🛍️</span> View Shop</a>

  <div class="sidebar-footer">
    <a href="../auth/logout.php" class="logout-link"><span style="font-size:15px">⇦</span> Logout</a>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">
<div class="page">

<?php if($success):?><div class="s-success">✅ <?=htmlspecialchars($success)?></div><?php endif;?>
<?php if($err):?><div class="s-alert-err">⚠ <?=htmlspecialchars($err)?></div><?php endif;?>

<?php if(!$store):?>
<div class="no-store-card">
  <div style="font-size:40px;margin-bottom:12px">🏪</div>
  <h3>Set Up Your Store First</h3>
  <p>Create your store profile to start selling handmade products and reaching buyers across India.</p>
  <button class="s-form-btn" onclick="switchTab('store')">🏬 Create My Store →</button>
</div>
<?php endif;?>

<!-- ══════════════ TAB: OVERVIEW ══════════════ -->
<div id="tab-overview" class="tab-panel <?=$tab==='overview'?'active':''?>">

<!-- Welcome Band -->
<div class="s-welcome">
  <div style="position:relative;z-index:1">
    <div class="sw-title"><span id="greetTime">Hello</span>, <?=htmlspecialchars($user['name'])?> 🏪</div>
    <div class="sw-sub">
      <?=$total_products?> products · <?=$total_orders?> orders · ₹<?=number_format($total_revenue,0)?> revenue
      <?php if($low_stock>0):?> · <span style="color:#FDE68A;font-weight:700">⚠ <?=$low_stock?> low stock</span><?php endif;?>
    </div>
  </div>
  <div class="sw-actions">
    <button class="sw-btn white" onclick="switchTab('add_product')">➕ Add Product</button>
    <button class="sw-btn glass" onclick="switchTab('orders')">🛒 <?=$pending_orders>0?"$pending_orders Pending":'Orders'?></button>
    <button class="sw-btn glass" onclick="switchTab('ai')">🤖 AI</button>
  </div>
</div>

<!-- KPI Cards -->
<div class="s-kpi-row">
  <div class="s-kpi k1">
    <div class="s-kpi-icon" style="background:rgba(0,109,119,.1)">📦</div>
    <div class="s-kpi-val" data-count="<?=$total_products?>" data-suffix=""><?=$total_products?></div>
    <div class="s-kpi-label">Live Products</div>
  </div>
  <div class="s-kpi k2">
    <div class="s-kpi-icon" style="background:rgba(255,138,0,.1)">🛒</div>
    <div class="s-kpi-val" data-count="<?=$total_orders?>" data-suffix=""><?=$total_orders?></div>
    <div class="s-kpi-label">Total Orders</div>
  </div>
  <div class="s-kpi k3">
    <div class="s-kpi-icon" style="background:rgba(37,99,235,.1)">💰</div>
    <div class="s-kpi-val">₹<?=number_format($total_revenue,0)?></div>
    <div class="s-kpi-label">Revenue</div>
  </div>
  <div class="s-kpi k4">
    <div class="s-kpi-icon" style="background:rgba(220,38,38,.1)">⏳</div>
    <div class="s-kpi-val" data-count="<?=$pending_orders?>" data-suffix=""><?=$pending_orders?></div>
    <div class="s-kpi-label">Pending</div>
  </div>
  <div class="s-kpi k5">
    <div class="s-kpi-icon" style="background:rgba(245,158,11,.1)">⚠️</div>
    <div class="s-kpi-val" data-count="<?=$low_stock?>" data-suffix=""><?=$low_stock?></div>
    <div class="s-kpi-label">Low Stock</div>
  </div>
</div>

<!-- AI Quick Insights -->
<div class="s-ai-panel">
  <div class="s-ai-hdr">
    <div class="s-ai-hdr-icon">🤖</div>
    <div class="s-ai-hdr-text">
      <h4>AI Seller Insights</h4>
      <p><span class="ai-live-dot"></span>Live · Personalised for your store</p>
    </div>
    <button onclick="switchTab('ai')" style="padding:6px 13px;border-radius:20px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.1);color:rgba(255,255,255,.85);font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap">See All →</button>
  </div>
  <div class="s-ai-body">
    <?php $sug = !empty($ai_recs) ? array_slice($ai_recs,0,2) : [
      ['icon'=>'💡','text'=>'<strong>Tip:</strong> Products with 3+ photos get 60% more clicks. Add high-quality images to all listings.'],
      ['icon'=>'📈','text'=>'<strong>Demand Alert:</strong> Handicraft and organic products are trending this season. Consider adding new listings.'],
    ]; foreach($sug as $r):?>
    <div class="s-ai-sug"><span class="s-ai-sug-icon"><?=htmlspecialchars($r['icon']??'💡')?></span><span class="s-ai-sug-text"><?=$r['text']??''?></span></div>
    <?php endforeach;?>
  </div>
</div>

<!-- Recent Orders (card view) -->
<?php if(!empty($orders)):?>
<div class="s-sec-head">
  <h3>🕐 Recent Orders</h3>
  <button class="s-sec-btn" onclick="switchTab('orders')">View All (<?=count($orders)?>) →</button>
</div>
<div class="s-order-list">
  <?php foreach(array_slice($orders,0,3) as $o):
    $ff = $fraud_flags[(int)$o['id']] ?? null;
  ?>
  <div class="s-order-card">
    <div class="s-order-top">
      <div>
        <div class="s-order-num">
          <span style="font-family:monospace;background:rgba(0,109,119,.08);color:var(--s1);padding:3px 10px;border-radius:20px"><?=htmlspecialchars($o['order_number'])?></span>
        </div>
        <div class="s-order-date">📅 <?=date('d M Y · h:i A',strtotime($o['created_at']))?></div>
      </div>
      <div class="s-order-badges">
        <span class="pill <?=htmlspecialchars($o['order_status'])?>"><?=ucfirst(str_replace('_',' ',$o['order_status']))?></span>
        <?php if($ff):?><span class="fraud-badge <?=$ff['risk']?>">⚠ <?=$ff['risk']?></span><?php else:?><span class="fraud-badge low">✓</span><?php endif;?>
      </div>
    </div>
    <div class="s-order-items"><?=htmlspecialchars($o['items']??'—')?></div>
    <div class="s-order-meta">
      <span class="s-order-amount">₹<?=number_format($o['total_amount'],0)?></span>
      <span class="s-order-ship">📍 <?=htmlspecialchars($o['shipping_city']??' ')?>, <?=htmlspecialchars($o['shipping_state']??'')?></span>
    </div>
  </div>
  <?php endforeach;?>
</div>
<?php endif;?>

<!-- Quick actions -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
  <button onclick="switchTab('add_product')" style="background:var(--sgr);color:#fff;border:none;border-radius:14px;padding:16px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(0,109,119,.2)">
    <span style="font-size:20px">➕</span><span>Add New Product</span>
  </button>
  <button onclick="switchTab('store')" style="background:#fff;color:var(--navy);border:1.5px solid var(--border);border-radius:14px;padding:16px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px">
    <span style="font-size:20px">🏬</span><span><?=$store?'Update Store':'Setup Store'?></span>
  </button>
</div>

</div><!-- /overview -->


<!-- ══════════════ TAB: AI INTELLIGENCE ══════════════ -->
<div id="tab-ai" class="tab-panel <?=$tab==='ai'?'active':''?>">

<!-- Full AI Panel -->
<div class="s-ai-panel" style="margin-bottom:20px">
  <div class="s-ai-hdr">
    <div class="s-ai-hdr-icon">🤖</div>
    <div class="s-ai-hdr-text">
      <h4>AI Seller Intelligence</h4>
      <p><span class="ai-live-dot"></span>Demand forecast · Pricing · Sentiment · Growth</p>
    </div>
  </div>
  <div class="s-ai-body">
    <?php $recs = !empty($ai_recs) ? array_slice($ai_recs,0,4) : [
      ['icon'=>'📦','text'=>'<strong>Stock Alert:</strong> Add 10+ products to maximise store visibility and unlock personalised AI recommendations.'],
      ['icon'=>'📸','text'=>'<strong>Photo Tips:</strong> Products with 3+ photos get 60% more clicks. Upload clear, well-lit images.'],
      ['icon'=>'🌿','text'=>'<strong>Trending:</strong> Organic and handicraft products are in high demand this season.'],
      ['icon'=>'💰','text'=>'<strong>Pricing:</strong> Check AI pricing suggestions per product to stay competitive and maximise revenue.'],
    ]; foreach($recs as $r):?>
    <div class="s-ai-sug"><span class="s-ai-sug-icon"><?=htmlspecialchars($r['icon']??'💡')?></span><span class="s-ai-sug-text"><?=$r['text']??''?></span></div>
    <?php endforeach;?>
  </div>
</div>

<!-- Demand Forecast Grid -->
<?php if(!empty($ai_demand)):?>
<div class="s-sec-head"><h3>📈 AI Demand Forecast</h3></div>
<div class="demand-grid">
  <?php foreach(array_slice($ai_demand,0,8) as $d):?>
  <div class="demand-card">
    <?php if($d['trending']??false):?><span class="trend-badge hot">🔥 Hot</span>
    <?php elseif($d['seasonal']??false):?><span class="trend-badge season">📅 Seasonal</span><?php endif;?>
    <div class="demand-label"><?=$cat_icons[$d['category']??'other']??'📦'?> <?=htmlspecialchars(str_replace('_',' ',ucfirst($d['category']??'')))?></div>
    <div class="demand-score"><?=(int)($d['score']??0)?><span style="font-size:13px;color:var(--muted)">/100</span></div>
    <div class="demand-bar-wrap"><div class="demand-bar" style="width:<?=(int)($d['score']??0)?>%"></div></div>
    <div class="demand-advice"><?=htmlspecialchars($d['advice']??'')?></div>
  </div>
  <?php endforeach;?>
</div>
<?php endif;?>

<!-- AI Product Description Generator -->
<div class="s-form-card">
  <h3>✍️ AI Product Description</h3>
  <p class="s-form-sub">Enter product details — AI generates a professional, SEO-friendly description instantly.</p>
  <div class="s-form-grid">
    <div class="sf"><label>Product Name *</label><input type="text" id="descName" placeholder="e.g. Hand-woven Cotton Saree"></div>
    <div class="sf"><label>Category *</label>
      <select id="descCat"><?php foreach($cats as $v=>$l):?><option value="<?=$v?>"><?=$l?></option><?php endforeach;?></select>
    </div>
    <div class="sf"><label>Price (₹)</label><input type="number" id="descPrice" placeholder="299" inputmode="numeric"></div>
    <div class="sf"><label>Material (optional)</label><input type="text" id="descMat" placeholder="e.g. pure cotton, organic silk"></div>
  </div>
  <button class="s-form-btn" onclick="generateDesc()">🤖 Generate Description</button>
  <div class="desc-result" id="descResult"></div>
</div>

<!-- AI Chatbot -->
<div class="s-chat-wrap">
  <div class="s-chat-hdr" onclick="toggleSChat()">
    <div style="width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">🤖</div>
    <h4>AI Seller Assistant</h4>
    <div class="s-chat-hdr-right">
      <span class="ai-live-dot"></span>
      <span id="schatLabel">Ask me anything →</span>
      <span id="schatArrow">▼</span>
    </div>
  </div>
  <div id="schatBody" style="display:none">
    <div class="s-chat-msgs" id="schatMessages"></div>
    <div class="s-chat-quick">
      <button class="s-cqb" onclick="sChat('Show demand forecast')">📈 Demand</button>
      <button class="s-cqb" onclick="sChat('Pricing suggestions for my products')">💰 Pricing</button>
      <button class="s-cqb" onclick="sChat('Show my revenue stats')">💵 Revenue</button>
      <button class="s-cqb" onclick="sChat('Review sentiment for my products')">⭐ Reviews</button>
    </div>
    <div class="s-chat-input-row">
      <input class="s-chat-input" id="schatInput" placeholder="Ask about pricing, demand, reviews…" maxlength="300" autocomplete="off" inputmode="text">
      <button class="s-chat-send" onclick="sChat(document.getElementById('schatInput').value)">➤</button>
    </div>
  </div>
</div>

</div><!-- /ai -->


<!-- ══════════════ TAB: STORE SETUP ══════════════ -->
<div id="tab-store" class="tab-panel <?=$tab==='store'?'active':''?>">
<div class="s-form-card">
  <h3><?=$store?'🏬 Update Store':'🏬 Set Up Your Store'?></h3>
  <p class="s-form-sub">This is how customers see your brand on SoulServe Shop. A great store profile builds trust and increases sales.</p>
  <form method="POST" action="../api/seller_setup.php" enctype="multipart/form-data">
    <?=csrf_field()?>
    <div class="s-form-grid">
      <div class="sf"><label>Store Name *</label><input type="text" name="store_name" value="<?=htmlspecialchars($store['store_name']??'')?>" required placeholder="e.g. Priya's Handlooms"></div>
      <div class="sf"><label>Category *</label>
        <select name="store_category" required><?php foreach($cats as $v=>$l):?><option value="<?=$v?>" <?=($store['store_category']??'')===$v?'selected':''?>><?=$cat_icons[$v]??''?> <?=$l?></option><?php endforeach;?></select>
      </div>
      <div class="sf full"><label>Tagline</label><input type="text" name="store_tagline" value="<?=htmlspecialchars($store['store_tagline']??'')?>" placeholder="A short catchy line about your store"></div>
      <div class="sf full"><label>Store Description *</label><textarea name="store_description" required placeholder="Tell customers your story — who you are, how you make your products, what makes them special…"><?=htmlspecialchars($store['store_description']??'')?></textarea></div>
      <div class="sf"><label>Store Logo</label><input type="file" name="store_logo" accept="image/*"><?php if(!empty($store['store_logo'])):?><div style="font-size:11px;color:var(--muted);margin-top:4px">✓ <?=basename($store['store_logo'])?></div><?php endif;?></div>
      <div class="sf"><label>WhatsApp Number</label><input type="tel" name="whatsapp" value="<?=htmlspecialchars($store['whatsapp']??'')?>" placeholder="+91 98765 43210" inputmode="tel"></div>
      <div class="sf"><label>Village / Town</label><input type="text" name="village" value="<?=htmlspecialchars($store['village']??'')?>" placeholder="Your village or town"></div>
      <div class="sf"><label>District</label><input type="text" name="district" value="<?=htmlspecialchars($store['district']??'')?>" placeholder="District"></div>
      <div class="sf"><label>State</label><input type="text" name="state" value="<?=htmlspecialchars($store['state']??'')?>" placeholder="State"></div>
    </div>
    <button type="submit" class="s-form-btn">💾 <?=$store?'Update Store':'Create Store'?> →</button>
  </form>
</div>
</div><!-- /store -->


<!-- ══════════════ TAB: ADD PRODUCT ══════════════ -->
<div id="tab-add_product" class="tab-panel <?=$tab==='add_product'?'active':''?>">
<?php if(!$store):?>
<div class="no-store-card">
  <div style="font-size:36px;margin-bottom:10px">🏬</div>
  <h3>Set Up Your Store First</h3>
  <p>You need a store profile before adding products.</p>
  <button class="s-form-btn" onclick="switchTab('store')">Create Store →</button>
</div>
<?php else:?>
<div class="s-form-card">
  <h3>➕ New Product Listing</h3>
  <p class="s-form-sub">Clear photos and honest descriptions sell better. Use the AI Description Generator below to write great copy instantly!</p>
  <form method="POST" action="../api/add_product.php" enctype="multipart/form-data" id="addProductForm">
    <?=csrf_field()?>
    <div class="s-form-grid">
      <div class="sf full">
        <label>Product Name *</label>
        <input type="text" name="name" required placeholder="e.g. Hand-woven Organic Cotton Saree" id="addProdName">
      </div>
      <div class="sf full">
        <label>Description * <button type="button" onclick="autoFillDesc()" style="float:right;font-size:11px;font-weight:700;color:var(--s1);background:rgba(0,109,119,.08);border:none;border-radius:20px;padding:3px 10px;cursor:pointer">🤖 AI Generate</button></label>
        <textarea name="description" required placeholder="Describe material, size, how it's made, what makes it special…" id="productDescTA"></textarea>
      </div>
      <div class="sf"><label>Category *</label>
        <select name="category" required id="addProdCat"><?php foreach($cats as $v=>$l):?><option value="<?=$v?>"><?=$cat_icons[$v]??''?> <?=$l?></option><?php endforeach;?></select>
      </div>
      <div class="sf"><label>Selling Price (₹) *</label><input type="number" name="price" min="1" step="0.01" required placeholder="e.g. 299" inputmode="decimal"></div>
      <div class="sf"><label>MRP / Original Price (₹)</label><input type="number" name="mrp" min="1" step="0.01" placeholder="e.g. 499" inputmode="decimal"></div>
      <div class="sf"><label>Stock Quantity *</label><input type="number" name="stock" min="0" required placeholder="How many pieces?" inputmode="numeric"></div>
      <div class="sf"><label>Weight (grams)</label><input type="number" name="weight_grams" min="1" placeholder="e.g. 500" inputmode="numeric"></div>
      <div class="sf"><label>Main Photo * <span style="font-size:10px;color:var(--muted);font-weight:500">High quality, clear background</span></label><input type="file" name="image1" accept="image/*" required></div>
      <div class="sf"><label>Photo 2 (optional)</label><input type="file" name="image2" accept="image/*"></div>
      <div class="sf"><label>Photo 3 (optional)</label><input type="file" name="image3" accept="image/*"></div>
    </div>
    <div style="background:rgba(0,109,119,.05);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:var(--muted)">
      💡 <strong>Tips:</strong> Use natural light · Show product from multiple angles · Include scale reference · Avoid busy backgrounds
    </div>
    <button type="submit" class="s-form-btn">🚀 List Product →</button>
  </form>
</div>
<?php endif;?>
</div><!-- /add_product -->


<!-- ══════════════ TAB: MY PRODUCTS ══════════════ -->
<div id="tab-products" class="tab-panel <?=$tab==='products'?'active':''?>">
<div class="s-sec-head">
  <h3>📦 My Products <span style="background:rgba(0,109,119,.1);color:var(--s1);font-size:11px;padding:2px 10px;border-radius:20px;font-weight:700"><?=count($products)?></span></h3>
  <button class="s-sec-btn" onclick="switchTab('add_product')">➕ Add New</button>
</div>

<?php if($low_stock>0):?>
<div class="s-alert-warn">
  <span>⚠️</span>
  <?=$low_stock?> product<?=$low_stock>1?'s':''?> with 5 or fewer units in stock. Restock soon to avoid missed orders.
  <button onclick="document.querySelectorAll('.s-prod-card').forEach(c=>{if(c.dataset.lowstock)c.scrollIntoView({behavior:'smooth'})})" style="margin-left:auto;background:none;border:none;font-size:12px;font-weight:700;color:#92400e;cursor:pointer;text-decoration:underline">Show →</button>
</div>
<?php endif;?>

<?php if(empty($products)):?>
<div class="s-empty"><span class="emoji">📦</span><p>No products yet.<br><a href="#" onclick="switchTab('add_product');return false" style="color:var(--s1);font-weight:700">Add your first listing →</a></p></div>
<?php else:?>
<div class="s-prod-grid">
  <?php foreach($products as $p):
    $img  = !empty($p['image1']) ? image_url($p['image1']) : null;
    $pr   = $pricing[$p['id']] ?? null;
    $sent = $sentiment[$p['id']] ?? null;
    $low  = (int)$p['stock'] <= 5 && (int)$p['stock'] > 0;
    $out  = (int)$p['stock'] === 0;
  ?>
  <div class="s-prod-card" <?=$low?'data-lowstock="1"':''?>>
    <?php if($img):?><img src="<?=htmlspecialchars($img)?>" class="s-prod-img" alt="<?=htmlspecialchars($p['name'])?>" loading="lazy">
    <?php else:?><div class="s-prod-img-ph"><?=$cat_icons[$p['category']??'other']??'🛍️'?></div><?php endif;?>
    <?php if($out):?><div style="position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:1"><span style="background:#ef4444;color:#fff;font-size:11px;font-weight:800;padding:5px 14px;border-radius:20px">OUT OF STOCK</span></div><?php endif;?>
    <div class="s-prod-body">
      <div class="s-prod-name" title="<?=htmlspecialchars($p['name'])?>"><?=htmlspecialchars($p['name'])?></div>
      <div>
        <span class="s-prod-price">₹<?=number_format($p['price'],0)?></span>
        <?php if($p['mrp']>$p['price']):?><span class="s-prod-mrp">₹<?=number_format($p['mrp'],0)?></span><?php endif;?>
      </div>
      <div class="s-prod-meta">
        Stock: <strong style="color:<?=$out?'#ef4444':($low?'#d97706':'var(--navy)')?>;"><?=(int)$p['stock']?></strong> · Sold: <?=(int)$p['total_sold']?> · ⭐ <?=number_format($p['avg_rating'],1)?> (<?=(int)($p['rev_count']??0)?> reviews)
      </div>
      <?php if($low && !$out):?><div class="s-low-stock">⚡ Low — restock soon</div><?php endif;?>
      <?php if($pr && abs((float)$pr['suggested_price']-(float)$p['price'])>=5):?>
      <div class="s-prod-ai">💰 AI: ₹<?=number_format((float)$pr['suggested_price'],0)?> · <?=htmlspecialchars(substr($pr['action']??'',0,40))?></div>
      <?php endif;?>
      <?php if($sent):?>
      <div class="s-prod-ai">⭐ <?=$sent['score']?>% positive · <?=htmlspecialchars(substr($sent['summary']??'',0,45))?></div>
      <?php endif;?>
      <div class="s-prod-footer">
        <?php if($p['is_active']):?><span class="pill-active">Active</span><?php else:?><span class="pill-inactive">Inactive</span><?php endif;?>
        <form method="POST" action="../api/add_product.php" style="margin-left:auto">
          <?=csrf_field()?><input type="hidden" name="toggle_id" value="<?=(int)$p['id']?>"><input type="hidden" name="active" value="<?=$p['is_active']?0:1?>">
          <button type="submit" style="font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;border:none;cursor:pointer;background:<?=$p['is_active']?'#fef3c7':'#d1fae5'?>;color:<?=$p['is_active']?'#92400e':'#065f46'?>;-webkit-tap-highlight-color:transparent"><?=$p['is_active']?'Pause':'Activate'?></button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach;?>
</div>
<?php endif;?>
</div><!-- /products -->


<!-- ══════════════ TAB: ORDERS ══════════════ -->
<div id="tab-orders" class="tab-panel <?=$tab==='orders'?'active':''?>">
<div class="s-sec-head">
  <h3>🛒 Orders <span style="background:rgba(0,109,119,.1);color:var(--s1);font-size:11px;padding:2px 10px;border-radius:20px;font-weight:700"><?=count($orders)?></span></h3>
  <?php if($pending_orders>0):?><span style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px">⏳ <?=$pending_orders?> Pending Action</span><?php endif;?>
</div>

<?php if(empty($orders)):?>
<div class="s-empty"><span class="emoji">🛒</span><p>No orders yet.<br>Keep building your store and listing great products!</p></div>
<?php else:?>
<div class="s-order-list">
  <?php foreach($orders as $o):
    $ff = $fraud_flags[(int)$o['id']] ?? null;
  ?>
  <div class="s-order-card">
    <div class="s-order-top">
      <div>
        <div class="s-order-num">
          <span style="font-family:monospace;font-size:12px;font-weight:800;color:var(--navy)"><?=htmlspecialchars($o['order_number'])?></span>
        </div>
        <div class="s-order-date">📅 <?=date('d M Y · h:i A',strtotime($o['created_at']))?></div>
      </div>
      <div class="s-order-badges">
        <span class="pill <?=htmlspecialchars($o['order_status'])?>"><?=ucfirst(str_replace('_',' ',$o['order_status']))?></span>
        <?php if($ff && ($ff['risk']??'low')!=='low'):?>
        <span class="fraud-badge <?=$ff['risk']?>" title="<?=htmlspecialchars(implode(', ',$ff['flags']??[]))?>">⚠ <?=$ff['risk']?></span>
        <?php endif;?>
      </div>
    </div>
    <div class="s-order-items">📦 <?=htmlspecialchars($o['items']??'—')?></div>
    <div class="s-order-meta">
      <span class="s-order-amount">₹<?=number_format($o['total_amount'],0)?></span>
      <span class="s-order-ship">📍 <?=htmlspecialchars($o['shipping_city']??'')?>  <?=htmlspecialchars($o['shipping_state']??'')?></span>
      <span style="font-size:11px;color:var(--muted)">👤 <?=htmlspecialchars($o['buyer_email']??'')?></span>
    </div>
    <!-- Action buttons -->
    <?php if($o['order_status']==='placed'):?>
    <div class="s-order-actions">
      <form method="POST" action="../api/update_order_status.php" style="flex:1">
        <?=csrf_field()?><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="status" value="confirmed">
        <button type="submit" class="s-order-action-btn confirm">✓ Confirm Order</button>
      </form>
    </div>
    <?php elseif($o['order_status']==='confirmed'):?>
    <form method="POST" action="../api/update_order_status.php">
      <?=csrf_field()?><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="status" value="shipped">
      <div class="s-order-actions">
        <input type="text" name="tracking_id" placeholder="Tracking ID (optional)" class="tracking-input">
        <button type="submit" class="s-order-action-btn ship">🚚 Mark Shipped</button>
      </div>
    </form>
    <?php elseif($o['order_status']==='shipped'):?>
    <div class="s-order-actions">
      <form method="POST" action="../api/update_order_status.php" style="flex:1">
        <?=csrf_field()?><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="status" value="out_for_delivery">
        <button type="submit" class="s-order-action-btn deliver">📦 Out for Delivery</button>
      </form>
    </div>
    <?php endif;?>
    <?php if(!empty($o['tracking_id'])):?>
    <div style="font-size:11px;color:var(--muted);margin-top:8px;display:flex;align-items:center;gap:6px">
      🔍 Tracking: <strong style="color:var(--navy)"><?=htmlspecialchars($o['tracking_id'])?></strong>
    </div>
    <?php endif;?>
  </div>
  <?php endforeach;?>
</div>
<?php endif;?>
</div><!-- /orders -->


<!-- ══════════════ TAB: ANALYTICS ══════════════ -->
<div id="tab-analytics" class="tab-panel <?=$tab==='analytics'?'active':''?>">

<div class="s-sec-head"><h3>📈 Sales Analytics</h3></div>

<!-- Revenue chart -->
<div class="chart-wrap">
  <h4>7-Day Revenue</h4>
  <p>Daily revenue from completed orders (last 7 days)</p>
  <div class="chart-canvas-wrap">
    <canvas id="revenueChart"></canvas>
  </div>
</div>

<!-- Stats grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:20px">
  <?php
  $delivered = sq_i($conn,"SELECT COUNT(*) c FROM orders WHERE seller_email='$me' AND order_status='delivered'");
  $cancelled = sq_i($conn,"SELECT COUNT(*) c FROM orders WHERE seller_email='$me' AND order_status IN ('cancelled','returned')");
  $total_reviews = sq_i($conn,"SELECT COUNT(*) c FROM product_reviews pr JOIN products p ON p.id=pr.product_id WHERE p.seller_email='$me'");
  $avg_rating = (float)$conn->query("SELECT COALESCE(AVG(pr.rating),0) r FROM product_reviews pr JOIN products p ON p.id=pr.product_id WHERE p.seller_email='$me'")->fetch_assoc()['r'];
  $delivery_rate = $total_orders > 0 ? round($delivered/$total_orders*100) : 0;
  foreach([
    ['🎯','Delivery Rate',"$delivery_rate%",'Orders fulfilled successfully'],
    ['⭐','Avg Rating',number_format($avg_rating,1)." / 5","From $total_reviews reviews"],
    ['✅','Delivered',$delivered,'Completed orders'],
    ['❌','Cancelled',$cancelled,'Cancelled / returned'],
  ] as $stat):?>
  <div style="background:#fff;border-radius:16px;padding:18px;box-shadow:0 4px 14px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06)">
    <div style="font-size:24px;margin-bottom:8px"><?=$stat[0]?></div>
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:5px"><?=$stat[1]?></div>
    <div style="font-size:22px;font-weight:900;color:var(--navy);margin-bottom:3px"><?=$stat[2]?></div>
    <div style="font-size:11px;color:var(--muted)"><?=$stat[3]?></div>
  </div>
  <?php endforeach;?>
</div>

<!-- Top products table -->
<?php if(!empty($products)):?>
<div class="s-form-card">
  <h3>🏆 Top Products by Sales</h3>
  <p class="s-form-sub" style="margin-bottom:14px">Ranked by units sold</p>
  <?php $sorted = $products; usort($sorted,fn($a,$b)=>$b['total_sold']-$a['total_sold']); ?>
  <?php foreach(array_slice($sorted,0,5) as $i=>$p):?>
  <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid rgba(16,42,67,.06)">
    <div style="width:28px;height:28px;border-radius:50%;background:var(--sgr);color:#fff;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?=$i+1?></div>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($p['name'])?></div>
      <div style="font-size:11px;color:var(--muted)">₹<?=number_format($p['price'],0)?> · Stock: <?=(int)$p['stock']?></div>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <div style="font-size:15px;font-weight:800;color:var(--s1)"><?=(int)$p['total_sold']?></div>
      <div style="font-size:10px;color:var(--muted)">sold</div>
    </div>
  </div>
  <?php endforeach;?>
</div>
<?php endif;?>
</div><!-- /analytics -->


<!-- ══════════════ TAB: BANK DETAILS ══════════════ -->
<div id="tab-bank" class="tab-panel <?=$tab==='bank'?'active':''?>">
<div class="s-form-card">
  <h3>🏦 Bank &amp; Payment Details</h3>
  <p class="s-form-sub">Used for order settlements. Kept encrypted and never shared publicly.</p>
  <div class="bank-info-note">
    🔒 Your banking details are encrypted and used only for payment settlements. <strong>Never share your OTP or password</strong> with anyone — including SoulServe.
  </div>
  <form method="POST" action="../api/seller_setup.php" enctype="multipart/form-data">
    <?=csrf_field()?>
    <input type="hidden" name="bank_only" value="1">
    <div class="s-form-grid">
      <div class="sf"><label>UPI ID</label><input type="text" name="upi_id" value="<?=htmlspecialchars($store['upi_id']??'')?>" placeholder="yourname@upi" inputmode="email"></div>
      <div class="sf"><label>Bank Name</label><input type="text" name="bank_name" value="<?=htmlspecialchars($store['bank_name']??'')?>" placeholder="e.g. State Bank of India"></div>
      <div class="sf"><label>Account Holder Name</label><input type="text" name="bank_holder_name" value="<?=htmlspecialchars($store['bank_holder_name']??'')?>" placeholder="Name as on passbook" autocomplete="name"></div>
      <div class="sf"><label>Account Number</label><input type="text" name="bank_account" value="<?=htmlspecialchars($store['bank_account']??'')?>" placeholder="Account number" inputmode="numeric"></div>
      <div class="sf"><label>IFSC Code</label><input type="text" name="bank_ifsc" value="<?=htmlspecialchars($store['bank_ifsc']??'')?>" placeholder="e.g. SBIN0001234"></div>
    </div>
    <button type="submit" class="s-form-btn">💾 Save Payment Details →</button>
  </form>
</div>
</div><!-- /bank -->


</div><!-- .page -->
</main>
</div><!-- .app -->

<div id="dashToast"></div>
<script src="../js/dashboard.js"></script>
<script defer src="../js/ai_chat.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
/* ── TAB SWITCHING ── */
function switchTab(t) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
  // Bottom nav
  document.querySelectorAll('.bn-item').forEach(b => b.classList.toggle('active', b.getAttribute('onclick')?.includes("'"+t+"'")));
  const panel = document.getElementById('tab-' + t);
  if (panel) {
    panel.classList.add('active');
    // Scroll to top of main on mobile
    if (window.innerWidth <= 700) window.scrollTo({top:0,behavior:'smooth'});
  }
  history.replaceState(null, '', '?tab=' + t);
  // Init chart on analytics tab
  if (t === 'analytics') initRevenueChart();
}
/* Also support goTab alias for backward compat */
window.goTab = switchTab;

/* ── REVENUE CHART ── */
let chartInstance = null;
function initRevenueChart(){
  const ctx = document.getElementById('revenueChart');
  if (!ctx || chartInstance) return;
  const labels = <?=json_encode($weekly_labels)?>;
  const data   = <?=json_encode(array_map('floatval',$weekly_rev))?>;
  chartInstance = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Revenue (₹)',
        data,
        backgroundColor: 'rgba(0,109,119,.15)',
        borderColor: '#006D77',
        borderWidth: 2,
        borderRadius: 8,
        hoverBackgroundColor: 'rgba(0,109,119,.3)',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(0,0,0,.05)' },
          ticks: { font: { size: 11 }, callback: v => '₹' + v.toLocaleString('en-IN') }
        },
        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
      }
    }
  });
}
// Init if landing on analytics tab
if (document.querySelector('#tab-analytics.active')) initRevenueChart();

/* ── AI CHATBOT ── */
let scOpen = false;
function toggleSChat() {
  scOpen = !scOpen;
  document.getElementById('schatBody').style.display = scOpen ? 'block' : 'none';
  document.getElementById('schatLabel').textContent = scOpen ? 'Minimize ▲' : 'Ask me anything →';
  document.getElementById('schatArrow').textContent = scOpen ? '▲' : '▼';
  if (scOpen && document.getElementById('schatMessages').children.length === 0) {
    addSMsg('🏪 Hi <?=addslashes(htmlspecialchars($user['name']))?> ! I\'m your AI Seller Assistant.<br>Ask me about demand, pricing, reviews, or generate product descriptions.', 'bot');
    setTimeout(() => document.getElementById('schatInput').focus(), 100);
  }
}
function addSMsg(text, role) {
  const w = document.getElementById('schatMessages');
  const d = document.createElement('div');
  d.className = 's-cmsg ' + role;
  d.innerHTML = `<div class="s-cico">${role==='bot'?'🤖':'👤'}</div><div class="s-cbub">${text}</div>`;
  w.appendChild(d);
  w.scrollTop = w.scrollHeight;
}
function sChat(q) {
  q = q.trim(); if (!q) return;
  document.getElementById('schatInput').value = '';
  if (!scOpen) toggleSChat();
  addSMsg(q, 'user');
  const qd = document.getElementById('schatBody').querySelector('.s-chat-quick');
  if (qd) qd.style.display = 'none';
  const w = document.getElementById('schatMessages');
  const td = document.createElement('div');
  td.className = 's-cmsg bot'; td.id = 'styping';
  td.innerHTML = '<div class="s-cico">🤖</div><div class="typing-dots"><span></span><span></span><span></span></div>';
  w.appendChild(td); w.scrollTop = w.scrollHeight;
  fetch('../api/ai_assistant.php', { method:'POST', body: new URLSearchParams({message:q, context:'seller'}) })
    .then(r => r.json())
    .then(d => { document.getElementById('styping')?.remove(); addSMsg(d.reply || 'Could not process.', 'bot'); })
    .catch(() => { document.getElementById('styping')?.remove(); addSMsg('Connection error. Please try again.', 'bot'); });
}
document.getElementById('schatInput').addEventListener('keydown', e => { if (e.key === 'Enter') sChat(e.target.value); });

/* ── AI DESCRIPTION GENERATOR ── */
function generateDesc() {
  const name  = document.getElementById('descName')?.value.trim() || document.getElementById('addProdName')?.value.trim() || '';
  const cat   = document.getElementById('descCat')?.value || document.getElementById('addProdCat')?.value || 'other';
  const price = parseFloat(document.getElementById('descPrice')?.value) || 0;
  const mat   = document.getElementById('descMat')?.value.trim() || '';
  if (!name) { alert('Please enter a product name first.'); return; }
  const r = document.getElementById('descResult');
  if (r) { r.style.display = 'block'; r.textContent = '🤖 Generating…'; }
  fetch('../api/ai_assistant.php', {
    method:'POST',
    body: new URLSearchParams({ message: `Generate a product description for: Name="${name}", Category="${cat}", Price=₹${price}, Material="${mat}"`, context:'seller' })
  }).then(r2 => r2.json()).then(d => {
    if (r) r.innerHTML = d.reply || 'Could not generate.';
    const ta = document.getElementById('productDescTA');
    if (ta && !ta.value && d.reply) ta.value = r?.innerText?.replace(/<[^>]+>/g,'').trim() || '';
  }).catch(() => { if (r) r.textContent = 'Generation failed. Please try again.'; });
}
function autoFillDesc() {
  const name = document.getElementById('addProdName')?.value.trim();
  if (!name) { alert('Please enter a product name first.'); return; }
  generateDesc();
  switchTab('add_product');
}

/* ── Greeting ── */
(function(){
  const el = document.getElementById('greetTime');
  if (!el) return;
  const h = new Date().getHours();
  el.textContent = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
})();

/* ── Count-up on KPIs ── */
document.querySelectorAll('[data-count]').forEach(el => {
  const target = parseInt(el.dataset.count) || 0;
  const suffix = el.dataset.suffix ?? '';
  if (target === 0) { el.textContent = '0' + suffix; return; }
  let cur = 0;
  const step = Math.max(1, Math.ceil(target / 60));
  const t = setInterval(() => {
    cur = Math.min(cur + step, target);
    el.textContent = cur.toLocaleString('en-IN') + (cur >= target ? suffix : '');
    if (cur >= target) clearInterval(t);
  }, 18);
});
</script>
<script src="../js/script.js"></script>
</body>
</html>
