<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
require_once __DIR__ . '/../api/ai_engine.php';
require_once __DIR__ . '/../api/award_badges.php';

/* ── Role-based access guard ── */
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
/* If role is set in session and is NOT donor, redirect to correct dashboard */
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'donor') {
    switch ($_SESSION['role']) {
        case 'volunteer': header("Location: ../volunteer/volunteer_dashboard.php"); exit;
        case 'seller':    header("Location: ../seller/seller_dashboard.php");      exit;
        case 'admin':     header("Location: ../admin/admin_dashboard.php");        exit;
    }
}
$email = $_SESSION['user_email'];
$u = $conn->prepare("SELECT * FROM register WHERE email=? AND role='donor' AND verified=1");
$u->bind_param("s",$email); $u->execute();
$user = $u->get_result()->fetch_assoc();
if (!$user) {
    /* User exists but not as donor — fix session role and redirect */
    $chk = $conn->prepare("SELECT role FROM register WHERE email=? AND verified=1");
    $chk->bind_param("s",$email); $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if ($row) {
        $_SESSION['role'] = $row['role'];
        switch ($row['role']) {
            case 'volunteer': header("Location: ../volunteer/volunteer_dashboard.php"); exit;
            case 'seller':    header("Location: ../seller/seller_dashboard.php");      exit;
        }
    }
    header("Location: ../auth/login.php"); exit;
}

/* ── helpers ── */
function sc2($conn,$sql,$p=null,$t=null){
  try{if($p){$s=$conn->prepare($sql);$s->bind_param($t,...$p);$s->execute();$r=$s->get_result();return(int)($r->fetch_assoc()['c']??0);}$r=$conn->query($sql);return($r?(int)$r->fetch_assoc()['c']:0);}catch(Throwable $e){return 0;}
}
function stepIndexD(string $s, array $steps):int{$i=array_search($s,$steps);return $i===false?0:(int)$i;}

/* ── counts ── */
$food  = sc2($conn,"SELECT COUNT(*) c FROM food_donations  WHERE donor_email=?",[$email],"s");
$cloth = sc2($conn,"SELECT COUNT(*) c FROM cloth_donations WHERE donor_email=?",[$email],"s");
$total = $food + $cloth;
$goal  = 20;
$pct   = min(100,round($total/max(1,$goal)*100));

/* ── recent & active ── */
$recent_food=$recent_cloth=[];
try{$rf=$conn->prepare("SELECT COALESCE(donation_id,CONCAT('DON-FOOD-',LPAD(id,6,'0'))) AS don_id,'Food' AS type,id,quantity,pickup_address,status,created_at,pickup_date,pickup_time,volunteer_email,priority FROM food_donations WHERE donor_email=? ORDER BY created_at DESC LIMIT 10");$rf->bind_param("s",$email);$rf->execute();$recent_food=$rf->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}
try{$rc=$conn->prepare("SELECT COALESCE(donation_id,CONCAT('DON-CLO-',LPAD(id,6,'0'))) AS don_id,'Clothes' AS type,id,quantity,pickup_address,status,created_at,pickup_date,pickup_time,volunteer_email,NULL AS priority FROM cloth_donations WHERE donor_email=? ORDER BY created_at DESC LIMIT 10");$rc->bind_param("s",$email);$rc->execute();$recent_cloth=$rc->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}
$recent = array_merge($recent_food,$recent_cloth);
usort($recent,fn($a,$b)=>strtotime($b['created_at'])-strtotime($a['created_at']));
$recent = array_slice($recent,0,10);
$active_arr = array_values(array_filter($recent,fn($r)=>!in_array($r['status'],['delivered','rejected'])));

/* ── counters ── */
$me          = mysqli_real_escape_string($conn,$email);
$cart_count  = sc2($conn,"SELECT COUNT(*) c FROM cart WHERE user_email='$me'");
$order_count = sc2($conn,"SELECT COUNT(*) c FROM orders WHERE buyer_email='$me'");

/* ── AI Engine calls ── */
$ai = adhaar_ai();
$ai_suggestions  = []; $ai_products = []; $ai_impact = [];
$ai_causes       = []; $ai_report   = []; $ai_alerts  = [];
$ai_recurring    = []; $ai_badges   = []; $ai_need    = [];
try {
  $ai_suggestions = $ai->getDonorSuggestions($email) ?: [];
  $ai_products    = $ai->getProductRecommendations($email,0,4) ?: [];
  $ai_impact      = $ai->predictImpact() ?: [];
  $ai_causes      = $ai->getPersonalizedCauses($email) ?: [];
  $ai_report      = $ai->generateMonthlyReport($email) ?: [];
  $ai_alerts      = $ai->getDonorAlerts($email) ?: [];
  $ai_recurring   = $ai->suggestRecurring($email) ?: [];
  // Award badges after every page load (idempotent)
  $newly_earned   = award_badges($conn,$email);
  $ai_badges      = get_donor_badges($conn,$email);
} catch(Throwable $e){}

/* ── AI impact vars ── */
$pf   = isset($ai_impact['people_fed'])    ? (int)$ai_impact['people_fed']    : null;
$co2  = isset($ai_impact['co2_saved_kg'])  ? (float)$ai_impact['co2_saved_kg'] : null;
$ecov = isset($ai_impact['economic_value'])? (int)$ai_impact['economic_value']  : null;

/* ── shop featured ── */
$featured = [];
try{$fq=$conn->query("SELECT p.*,s.store_name FROM products p JOIN seller_stores s ON s.seller_email=p.seller_email WHERE p.is_active=1 AND s.is_active=1 ORDER BY p.total_sold DESC,p.avg_rating DESC LIMIT 4");$featured=$fq?$fq->fetch_all(MYSQLI_ASSOC):[];}catch(Throwable $e){}

/* ── smart need match ── */
try{if(!empty($active_arr)){$d0=$active_arr[0];$ai_need=$ai->matchDonationToNeed($d0['type']==='Food'?'food':'cloth',(int)$d0['quantity'],$d0['pickup_address']??'');}}catch(Throwable $e){}

$success=$_GET['success']??''; $don_id=$_GET['don_id']??'';
$STATUS_STEPS =['pending','accepted','scheduled','out_for_pickup','picked_up','delivered'];
$STATUS_LABELS=['Submitted','Verified','Scheduled','Out for Pickup','Picked Up','Delivered'];
$STATUS_ICONS =['📝','✅','📅','🚚','📦','🎉'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Donor Dashboard — SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#102A43">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/dashboard.css">
<style>
:root{--dt:#006D77;--dg:#2E8B57;--do:#FF8A00;--dp:#F72585;--db:#2563EB;
  --dgr:linear-gradient(135deg,#006D77,#2E8B57);
  --dhr:linear-gradient(135deg,#102A43 0%,#006D77 55%,#2E8B57 100%);}
.welcome-band{background:var(--dhr);border-radius:24px;padding:28px 30px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;box-shadow:0 16px 48px rgba(0,109,119,.25);animation:fadeUp .5s ease}
.welcome-band::before{content:'';position:absolute;right:-50px;top:-50px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none}
.wb-title{font-size:20px;font-weight:800;margin-bottom:4px}.wb-sub{font-size:13px;opacity:.75}
.wb-btns{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.wb-btn{padding:9px 20px;border-radius:20px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;border:none;transition:.2s}
.wb-btn.white{background:#fff;color:var(--dt)}.wb-btn.white:hover{background:#e8fdf5;transform:translateY(-1px)}
.wb-btn.glass{background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.3)}.wb-btn.glass:hover{background:rgba(255,255,255,.25)}
/* KPIs */
.kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.kpi-card{background:#fff;border-radius:18px;padding:18px 16px;box-shadow:0 4px 20px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s;position:relative;overflow:hidden;cursor:default}
.kpi-card:hover{transform:translateY(-4px);box-shadow:0 12px 36px rgba(16,42,67,.12)}
.kpi-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 18px 18px;opacity:0;transition:.3s}
.kpi-card:hover::after{opacity:1}
.kpi-card.c1::after{background:var(--dgr)}.kpi-card.c2::after{background:linear-gradient(135deg,var(--do),var(--dp))}
.kpi-card.c3::after{background:linear-gradient(135deg,var(--db),#7B2CBF)}.kpi-card.c4::after{background:linear-gradient(135deg,var(--dg),var(--dt))}
.kpi-card.c5::after{background:linear-gradient(135deg,var(--dp),var(--db))}
.kpi-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:10px}
.kpi-val{font-size:28px;font-weight:900;color:var(--navy);line-height:1;margin-bottom:3px}
.kpi-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
/* Alerts */
.alert-strip{display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
.alert-item{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:14px;font-size:13px;font-weight:600}
.alert-item.high{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}
.alert-item.medium{background:#fef3c7;border:1px solid #fde68a;color:#92400e}
.alert-item.warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e}
.alert-item.low{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af}
.newly-earned{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border:1px solid #6ee7b7;border-radius:16px;padding:16px 20px;margin-bottom:18px;animation:fadeUp .4s ease}
/* AI panel */
.ai-panel{background:linear-gradient(135deg,#0a1f30,#0d3858 40%,#006d77 100%);border-radius:22px;padding:0;overflow:hidden;margin-bottom:24px;box-shadow:0 12px 40px rgba(0,109,119,.2)}
.ai-header{padding:16px 22px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.1)}
.ai-header-icon{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.ai-header-text h3{font-size:15px;font-weight:800;color:#fff;margin-bottom:2px}
.ai-header-text p{font-size:11px;color:rgba(255,255,255,.55)}
.ai-live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;animation:livePulse 1.6s ease infinite;margin-right:4px}
@keyframes livePulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}
.ai-body{padding:16px 22px;display:flex;flex-direction:column;gap:9px}
.ai-sug{display:flex;align-items:flex-start;gap:12px;padding:12px 15px;border-radius:13px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.08);transition:.2s}
.ai-sug:hover{background:rgba(255,255,255,.12)}
.ai-sug-icon{font-size:18px;flex-shrink:0;margin-top:1px}
.ai-sug-text{font-size:13px;color:rgba(255,255,255,.85);line-height:1.65}.ai-sug-text strong{color:#fff}
.ai-impact-strip{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid rgba(255,255,255,.1)}
.ai-imp{padding:14px;text-align:center;border-right:1px solid rgba(255,255,255,.1)}.ai-imp:last-child{border-right:none}
.ai-imp-val{font-size:20px;font-weight:900;color:#fff;line-height:1}
.ai-imp-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.5);margin-top:3px}
/* Chatbot */
.ai-chat-panel{background:#fff;border-radius:22px;border:1px solid rgba(16,42,67,.06);box-shadow:0 4px 20px rgba(16,42,67,.07);overflow:hidden;margin-bottom:24px}
.ai-chat-header{background:linear-gradient(135deg,#102A43,#006D77);padding:14px 20px;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.ai-chat-header h4{font-size:14px;font-weight:800;color:#fff;margin:0;flex:1}
.ai-chat-header span{font-size:11px;color:rgba(255,255,255,.6)}
.ai-chat-messages{height:260px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth;background:#f9fbfa}
.chat-msg{display:flex;gap:8px;align-items:flex-end;animation:fadeUp .2s ease}
.chat-msg.bot{flex-direction:row}.chat-msg.user{flex-direction:row-reverse}
.chat-bubble{max-width:80%;padding:10px 14px;border-radius:16px;font-size:13px;line-height:1.6}
.chat-msg.bot .chat-bubble{background:#fff;color:var(--navy);border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px;box-shadow:0 2px 8px rgba(16,42,67,.06)}
.chat-msg.user .chat-bubble{background:var(--dgr);color:#fff;border-radius:16px 4px 16px 16px}
.chat-icon{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.chat-msg.bot .chat-icon{background:linear-gradient(135deg,#006D77,#2E8B57);color:#fff}
.chat-msg.user .chat-icon{background:#e2ebe9;color:var(--navy)}
.chat-input-row{display:flex;border-top:1px solid rgba(16,42,67,.08)}
.chat-input{flex:1;padding:12px 16px;border:none;outline:none;font-size:13px;font-family:inherit;color:var(--navy);background:#fff}
.chat-input::placeholder{color:var(--muted)}
.chat-send{padding:0 18px;background:var(--dgr);color:#fff;border:none;cursor:pointer;font-size:15px;transition:.2s}
.chat-send:hover{opacity:.85}
.chat-quick{padding:8px 14px 10px;display:flex;gap:6px;flex-wrap:wrap;background:#f9fbfa;border-top:1px solid rgba(16,42,67,.06)}
.cq-btn{padding:5px 12px;border-radius:20px;border:1.5px solid rgba(0,109,119,.2);background:#fff;font-size:11px;font-weight:600;color:var(--dt);cursor:pointer;transition:.2s;font-family:inherit}
.cq-btn:hover{background:var(--dt);color:#fff;border-color:var(--dt)}
.typing-dots{display:flex;gap:4px;align-items:center;padding:10px 14px;background:#fff;border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px;width:fit-content}
.typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:tdot 1.2s infinite}
.typing-dots span:nth-child(2){animation-delay:.2s}.typing-dots span:nth-child(3){animation-delay:.4s}
@keyframes tdot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}
/* Sec head */
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.sec-head h3{font-size:15px;font-weight:800;color:var(--navy);display:flex;align-items:center;gap:8px}
.sec-head a{font-size:12px;font-weight:700;color:var(--teal);text-decoration:none;padding:5px 13px;border-radius:20px;background:rgba(0,109,119,.08);transition:.2s}
.sec-head a:hover{background:rgba(0,109,119,.15)}
/* Tracking */
.track-list{display:flex;flex-direction:column;gap:14px;margin-bottom:24px}
.track-card{background:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 4px 20px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);border-left:4px solid var(--dt);animation:fadeUp .4s ease}
.track-card.rejected{border-left-color:#ef4444}.track-card.delivered{border-left-color:var(--dg)}
.track-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.track-don-id{font-size:12px;font-weight:800;color:var(--navy);background:rgba(0,109,119,.08);padding:4px 12px;border-radius:20px;font-family:'Inter',monospace;letter-spacing:.3px}
.track-type{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px}
.track-type.food{background:#fef3c7;color:#92400e}.track-type.cloth{background:#dbeafe;color:#1e40af}
.track-meta{font-size:12px;color:var(--muted);margin-bottom:12px;display:flex;gap:14px;flex-wrap:wrap}
.prog-bar-wrap{margin-bottom:10px}
.prog-bar-track{height:5px;background:rgba(16,42,67,.08);border-radius:6px;overflow:hidden}
.prog-bar-fill{height:100%;background:var(--dgr);border-radius:6px;transition:width 1.2s cubic-bezier(.22,1,.36,1)}
.prog-pct{font-size:10px;font-weight:700;color:var(--dt);text-align:right;margin-top:3px}
.tl-steps{display:flex;align-items:flex-start;overflow-x:auto;padding-bottom:2px;gap:0;margin-top:4px;-webkit-overflow-scrolling:touch}
.tl-step{display:flex;flex-direction:column;align-items:center;flex:1;min-width:48px;text-align:center;gap:3px;position:relative}
.tl-dot{width:26px;height:26px;border-radius:50%;background:#e2ebe9;border:2px solid #e2ebe9;display:flex;align-items:center;justify-content:center;font-size:11px;transition:.4s;flex-shrink:0;z-index:1}
.tl-step.done .tl-dot{background:var(--dgr);border-color:transparent;color:#fff;box-shadow:0 4px 12px rgba(0,109,119,.3)}
.tl-step.active .tl-dot{background:#fff;border-color:var(--dt);color:var(--dt);box-shadow:0 0 0 4px rgba(0,109,119,.15);animation:stepPulse 1.8s ease infinite}
@keyframes stepPulse{0%,100%{box-shadow:0 0 0 4px rgba(0,109,119,.15)}50%{box-shadow:0 0 0 8px rgba(0,109,119,.06)}}
.tl-label{font-size:8px;font-weight:600;color:var(--muted);line-height:1.3;max-width:48px;word-break:break-word}
.tl-step.done .tl-label{color:var(--dt);font-weight:700}.tl-step.active .tl-label{color:var(--navy);font-weight:800}
.tl-line{flex:1;height:2px;background:#e2ebe9;margin-top:12px;transition:.4s;min-width:8px;align-self:flex-start}.tl-line.done{background:var(--dgr)}
/* Receipt link */
.receipt-link{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--dt);text-decoration:none;padding:5px 12px;border-radius:8px;background:rgba(0,109,119,.07);margin-top:10px;transition:.2s}
.receipt-link:hover{background:rgba(0,109,119,.14)}
/* Causes */
.causes-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:24px}
.cause-card{background:#fff;border-radius:18px;padding:18px;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s;position:relative;overflow:hidden}
.cause-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(16,42,67,.1)}
.cause-urgency{position:absolute;top:14px;right:14px;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.3px}
.cause-urgency.urgent,.cause-urgency.high{background:#fee2e2;color:#991b1b}
.cause-urgency.medium{background:#fef3c7;color:#92400e}
.cause-urgency.low{background:#dbeafe;color:#1e40af}
.cause-icon{font-size:28px;margin-bottom:10px}
.cause-title{font-size:14px;font-weight:800;color:var(--navy);margin-bottom:6px}
.cause-desc{font-size:12px;color:var(--muted);line-height:1.65;margin-bottom:14px}
.cause-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 18px;border-radius:20px;background:var(--dgr);color:#fff;font-size:12px;font-weight:700;text-decoration:none;transition:.2s}
.cause-btn:hover{opacity:.88;transform:translateY(-1px)}
/* Badges */
.badges-wrap{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:24px}
.badge-chip{display:flex;align-items:center;gap:8px;padding:9px 14px;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.2s;cursor:default}
.badge-chip:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(16,42,67,.1)}
.badge-emoji{font-size:20px}.badge-name{font-size:12px;font-weight:700;color:var(--navy)}
.badge-empty{font-size:13px;color:var(--muted);font-style:italic}
/* Monthly report card */
.report-card{background:linear-gradient(135deg,#102A43,#006D77,#2E8B57);border-radius:22px;padding:24px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden}
.report-card::before{content:'';position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.06)}
.report-month{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.6);margin-bottom:8px}
.report-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:14px 0}
.rep-stat-val{font-size:22px;font-weight:900;color:#fff}
.rep-stat-lbl{font-size:10px;color:rgba(255,255,255,.55);font-weight:700;text-transform:uppercase;margin-top:2px}
.report-msg{font-size:13px;color:rgba(255,255,255,.8);line-height:1.65;margin-top:8px;background:rgba(255,255,255,.08);border-radius:12px;padding:12px}
/* Need match */
.need-card{background:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);margin-bottom:20px}
.need-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(16,42,67,.04)}
.need-row:last-child{border-bottom:none}
.need-pct{width:48px;height:48px;border-radius:50%;background:var(--dgr);color:#fff;font-size:13px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.need-org{font-size:14px;font-weight:700;color:var(--navy)}
.need-desc{font-size:12px;color:var(--muted);margin-top:2px}
/* Shop strip */
.shop-strip{background:linear-gradient(135deg,#102A43,#006D77);border-radius:22px;padding:18px 22px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.shop-strip h3{font-size:17px;font-weight:800;margin-bottom:4px}.shop-strip p{font-size:13px;opacity:.75}
.shop-strip-btn{padding:9px 18px;border-radius:20px;background:#fff;color:var(--dt);font-size:13px;font-weight:800;text-decoration:none;white-space:nowrap;transition:.2s;flex-shrink:0}
.shop-strip-btn:hover{background:#e8fdf5;transform:translateY(-1px)}
.pm-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.pm-card{background:#fff;border-radius:14px;overflow:hidden;cursor:pointer;box-shadow:0 4px 14px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.05);transition:.3s}
.pm-card:hover{transform:translateY(-4px);box-shadow:0 12px 28px rgba(16,42,67,.12)}
.pm-img,.pm-img-ph{width:100%;height:130px;object-fit:cover;background:linear-gradient(135deg,#edf5f2,#eef2ff);display:flex;align-items:center;justify-content:center;font-size:34px}
.pm-body{padding:10px 12px 12px}.pm-name{font-size:12px;font-weight:700;color:var(--navy);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pm-store{font-size:10px;color:var(--muted);margin-bottom:4px}.pm-price{font-size:14px;font-weight:900;color:var(--dt)}
/* Table */
.don-table-wrap{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);margin-bottom:24px}
.don-table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.don-table{width:100%;border-collapse:collapse;min-width:520px}
.don-table th{background:rgba(16,42,67,.03);padding:11px 15px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid rgba(16,42,67,.06);text-align:left;white-space:nowrap}
.don-table td{padding:12px 15px;font-size:13px;border-bottom:1px solid rgba(16,42,67,.04);vertical-align:middle}
.don-table tbody tr:last-child td{border-bottom:none}.don-table tbody tr:hover td{background:rgba(0,109,119,.03)}
.don-id-badge{font-size:11px;font-weight:700;background:rgba(0,109,119,.08);color:var(--dt);padding:3px 10px;border-radius:20px;white-space:nowrap;font-family:'Inter',monospace}
/* Status pills */
.pill{display:inline-block;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap;letter-spacing:.3px}
.pill.pending{background:#fef3c7;color:#92400e}.pill.accepted{background:#dbeafe;color:#1e40af}
.pill.scheduled{background:#ede9fe;color:#5b21b6}.pill.out_for_pickup{background:#fce7f3;color:#9d174d}
.pill.picked_up,.pill.delivered{background:#d1fae5;color:#065f46}.pill.rejected{background:#fee2e2;color:#991b1b}
/* Success notice */
.success-notice{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border:1px solid #6ee7b7;border-radius:16px;padding:15px 18px;margin-bottom:18px;display:flex;align-items:flex-start;gap:13px;animation:fadeUp .4s ease}
.success-notice-icon{font-size:22px;flex-shrink:0;margin-top:1px}
.success-notice h4{font-size:14px;font-weight:800;color:#065f46;margin-bottom:3px}
.success-notice p{font-size:12px;color:#047857}
.success-notice-id{display:inline-block;background:#fff;border:1px solid #6ee7b7;color:#065f46;font-weight:800;font-family:'Inter',monospace;font-size:12px;padding:3px 11px;border-radius:20px;margin-top:5px}
/* Recurring suggestion */
.rec-card{background:linear-gradient(135deg,rgba(0,109,119,.06),rgba(46,139,87,.04));border:1.5px solid rgba(0,109,119,.2);border-radius:18px;padding:18px 20px;margin-bottom:24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.rec-icon{font-size:28px;flex-shrink:0}.rec-text h4{font-size:14px;font-weight:800;color:var(--navy);margin-bottom:4px}
.rec-text p{font-size:12px;color:var(--muted);line-height:1.65}
.rec-btn{padding:9px 20px;border-radius:20px;background:var(--dgr);color:#fff;font-size:12px;font-weight:700;text-decoration:none;flex-shrink:0;transition:.2s}
.rec-btn:hover{opacity:.88}
/* How steps */
.how-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.how-step{background:#fff;border-radius:14px;padding:18px 14px;text-align:center;box-shadow:0 4px 14px rgba(16,42,67,.06);border:1px solid rgba(16,42,67,.05);transition:.3s}
.how-step:hover{transform:translateY(-4px)}.how-step-num{width:30px;height:30px;border-radius:50%;background:var(--dgr);color:#fff;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;box-shadow:0 4px 12px rgba(0,109,119,.3)}
.how-step-icon{font-size:24px;margin-bottom:7px}.how-step h4{font-size:12px;font-weight:800;color:var(--navy);margin-bottom:4px}
.how-step p{font-size:11px;color:var(--muted);line-height:1.6}
/* Donate CTA */
.donate-cta{background:linear-gradient(135deg,#FF8A00,#F72585);border-radius:20px;padding:22px 26px;color:#fff;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 12px 36px rgba(247,37,133,.2)}
.donate-cta h3{font-size:19px;font-weight:800;margin-bottom:4px}.donate-cta p{font-size:13px;opacity:.82}
.donate-cta-btn{padding:11px 26px;border-radius:20px;background:#fff;color:#F72585;font-size:13px;font-weight:800;text-decoration:none;white-space:nowrap;transition:.2s;flex-shrink:0}
.donate-cta-btn:hover{background:#fff3f7;transform:translateY(-1px)}
/* Mobile topbar */
.mobile-topbar{background:#fff;border-bottom:1px solid rgba(16,42,67,.08)}
/* Responsive */
@media(max-width:1200px){.kpi-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .kpi-row{grid-template-columns:repeat(3,1fr)}
  .pm-grid{grid-template-columns:repeat(2,1fr)}
  .how-grid{grid-template-columns:repeat(2,1fr)}
  .causes-grid{grid-template-columns:1fr}
  .report-grid{grid-template-columns:repeat(2,1fr)}
  .ai-impact-strip{grid-template-columns:1fr 1fr}
  .ai-imp:last-child{grid-column:1/-1;border-right:none;border-top:1px solid rgba(255,255,255,.1)}
  .vwb,.swb,.welcome-band{padding:20px 22px;border-radius:18px}
  .shop-strip{flex-direction:column;align-items:flex-start;gap:10px}
  .donate-cta{flex-direction:column;align-items:flex-start}
}
@media(max-width:700px){
  .kpi-row{grid-template-columns:repeat(2,1fr);gap:10px}
  .pm-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .how-grid{grid-template-columns:1fr 1fr;gap:10px}
  .causes-grid{grid-template-columns:1fr}
  .report-grid{grid-template-columns:1fr 1fr;gap:8px}
  .welcome-band{padding:16px;border-radius:16px}
  .wb-title{font-size:18px}
  .track-card{padding:14px}
  .page{padding:14px}
  .don-table th,.don-table td{padding:9px 10px;font-size:12px}
  .badges-wrap{gap:8px}
  .badge-chip{padding:7px 11px}
  .rec-card{flex-direction:column;align-items:flex-start}
  .ai-impact-strip{grid-template-columns:repeat(3,1fr)}
  .ai-imp{padding:12px 10px}
  .ai-imp-val{font-size:16px}
  .tl-steps{gap:0}
  .tl-dot{width:22px;height:22px;font-size:10px}
  .tl-label{font-size:7px;max-width:40px}
}
@media(max-width:480px){
  .kpi-row{grid-template-columns:1fr 1fr;gap:8px}
  .kpi-val{font-size:22px}
  .kpi-icon{width:36px;height:36px;font-size:17px;margin-bottom:8px}
  .pm-grid{grid-template-columns:1fr 1fr;gap:8px}
  .how-grid{grid-template-columns:1fr 1fr;gap:8px}
  .report-grid{grid-template-columns:1fr 1fr;gap:8px}
  .rep-stat-val{font-size:18px}
  .page{padding:12px}
  .welcome-band{padding:14px;border-radius:14px}
  .wb-title{font-size:16px}
  .wb-sub{font-size:12px}
  .wb-btns{gap:7px}
  .wb-btn{padding:7px 14px;font-size:12px}
  .track-card{padding:12px}
  .tl-dot{width:20px;height:20px}
  .ai-chat-messages{height:200px}
  .ai-impact-strip{grid-template-columns:repeat(3,1fr)}
  .sec-head h3{font-size:14px}
  .don-table-wrap{border-radius:12px}
  .success-notice{padding:12px 14px;gap:10px}
  .success-notice h4{font-size:13px}
  .shop-strip{padding:14px 16px}
  .shop-strip h3{font-size:15px}
}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
</style>
</head>
<body>
<!-- ══ MOBILE TOPBAR ══ -->
<div class="mobile-topbar">
  <div style="padding:0 16px;height:58px;display:flex;align-items:center;justify-content:space-between">
    <img src="../assets/logo.png" alt="SoulServe" style="height:30px;object-fit:contain" loading="eager">
    <button class="hamburger" id="hamburger" aria-label="Menu" aria-expanded="false"
      style="display:flex;flex-direction:column;gap:5px;cursor:pointer;padding:8px;background:none;border:none">
      <span style="display:block;width:22px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
      <span style="display:block;width:22px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
      <span style="display:block;width:22px;height:2px;background:var(--navy);border-radius:2px;transition:.3s"></span>
    </button>
  </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="app">
<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="sidebar-logo-mark"><img src="../assets/logo.png" alt="SoulServe" style="width:28px;height:28px;object-fit:contain;border-radius:6px;filter:brightness(0)invert(1)"></div>
    <div class="sidebar-logo-text"><strong>SoulServe</strong><span>Donor Portal</span></div>
  </div>
  <!-- Donor chip -->
  <div style="margin:8px 10px 4px;padding:12px 14px;background:rgba(255,255,255,.06);border-radius:12px;border:1px solid rgba(255,255,255,.08)">
    <div style="width:36px;height:36px;border-radius:50%;background:var(--gradient-teal);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;font-weight:800;margin-bottom:8px"><?=strtoupper(substr($user['name'],0,1))?></div>
    <div style="font-size:13px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($user['name'])?></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:1px">Donor · <?=count($ai_badges)?> Badges</div>
  </div>
  <div class="nav-sec">Dashboard</div>
  <a href="donor_dashboard.php" class="nav-btn active"><span class="nav-icon">🏠</span> Overview</a>
  <a href="donate.php"          class="nav-btn"><span class="nav-icon">🎁</span> New Donation</a>
  <a href="history.php"         class="nav-btn"><span class="nav-icon">📋</span> History</a>
  <a href="track.php"           class="nav-btn"><span class="nav-icon">📍</span> Live Track</a>
  <a href="schedule_donation.php" class="nav-btn"><span class="nav-icon">📅</span> Schedule</a>
  <a href="../pages/leaderboard.php" class="nav-btn"><span class="nav-icon">🏆</span> Leaderboard</a>
  <div class="nav-sec">Shop</div>
  <a href="../shop/shop.php"    class="nav-btn"><span class="nav-icon">🛍️</span> Browse Shop</a>
  <a href="../shop/cart.php"    class="nav-btn"><span class="nav-icon">🛒</span> My Cart<?php if($cart_count>0):?><span class="nav-badge"><?=$cart_count?></span><?php endif;?></a>
  <a href="../shop/my_orders.php" class="nav-btn"><span class="nav-icon">📦</span> My Orders<?php if($order_count>0):?><span class="nav-badge green"><?=$order_count?></span><?php endif;?></a>
  <div class="nav-sec">Account</div>
  <a href="edit_profile.php"    class="nav-btn"><span class="nav-icon">👤</span> Profile</a>
  <a href="../pages/impact.php" class="nav-btn"><span class="nav-icon">🌍</span> Impact Board</a>
  <div class="sidebar-footer"><a href="../auth/logout.php" class="logout-link"><span>⇦</span> Logout</a></div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">
<div class="page">

<?php /* ── Success notice ── */ if($success && $don_id): ?>
<div class="success-notice">
  <span class="success-notice-icon"><?=$success==='food'?'🍱':'👕'?></span>
  <div>
    <h4>Donation submitted!</h4>
    <p><?=$success==='food'?'Food':'Clothing'?> donation received. You'll be notified once verified.</p>
    <span class="success-notice-id"><?=htmlspecialchars($don_id)?></span>
    <br><a href="../api/donation_receipt.php?id=<?=urlencode(preg_replace('/^DON-(FOOD|CLO)-0*/','',htmlspecialchars($don_id)))?>&type=<?=$success?>" target="_blank" class="receipt-link" style="margin-top:8px">🖨️ Download Receipt</a>
  </div>
</div>
<?php elseif($success): ?>
<div class="success-notice">
  <span class="success-notice-icon"><?=$success==='food'?'🍱':'👕'?></span>
  <div><h4>Donation submitted!</h4><p>Pending review. Check the track page for updates.</p></div>
</div>
<?php endif; ?>

<?php /* ── Newly earned badges ── */ if(!empty($newly_earned)): ?>
<div class="newly-earned">
  <div style="font-size:13px;font-weight:800;color:#065f46;margin-bottom:8px">🎉 New Badge<?=count($newly_earned)>1?'s':''?> Earned!</div>
  <div style="display:flex;flex-wrap:wrap;gap:10px">
    <?php foreach($newly_earned as $b): ?>
    <div style="display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #6ee7b7;border-radius:10px;padding:6px 12px">
      <span style="font-size:18px"><?=htmlspecialchars($b['emoji'])?></span>
      <span style="font-size:12px;font-weight:700;color:#065f46"><?=htmlspecialchars($b['name'])?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php /* ── AI alerts (fraud/warnings) ── */ if(!empty($ai_alerts)): ?>
<div class="alert-strip">
  <?php foreach($ai_alerts as $al): ?>
  <div class="alert-item <?=htmlspecialchars($al['level'])?>">
    <span style="font-size:16px;flex-shrink:0"><?=$al['icon']?></span>
    <span><?=$al['msg']?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ WELCOME BAND ══ -->
<div class="welcome-band">
  <div style="position:relative;z-index:1">
    <div class="wb-title"><span id="greetTime">Hello</span>, <?=htmlspecialchars($user['name'])?> 👋</div>
    <div class="wb-sub">
      <?=$total?> donation<?=$total!=1?'s':''?> · <?=count($ai_badges)?> badge<?=count($ai_badges)!=1?'s':''?> · <?=$pct?>% toward your goal
    </div>
  </div>
  <div class="wb-btns">
    <a href="donate.php"           class="wb-btn white">🎁 Donate Now</a>
    <a href="track.php"            class="wb-btn glass">📍 Track</a>
    <a href="../shop/shop.php"     class="wb-btn glass">🛍️ Shop</a>
    <a href="schedule_donation.php"class="wb-btn glass">📅 Schedule</a>
  </div>
</div>

<!-- ══ KPI CARDS ══ -->
<div class="kpi-row">
  <div class="kpi-card c1">
    <div class="kpi-icon" style="background:rgba(0,109,119,.1)">🎁</div>
    <div class="kpi-val" data-count="<?=$total?>" data-suffix=""><?=$total?></div>
    <div class="kpi-label">Total Donations</div>
  </div>
  <div class="kpi-card c2">
    <div class="kpi-icon" style="background:rgba(255,138,0,.1)">🍱</div>
    <div class="kpi-val" data-count="<?=$food?>" data-suffix=""><?=$food?></div>
    <div class="kpi-label">Food Donations</div>
  </div>
  <div class="kpi-card c3">
    <div class="kpi-icon" style="background:rgba(37,99,235,.1)">👕</div>
    <div class="kpi-val" data-count="<?=$cloth?>" data-suffix=""><?=$cloth?></div>
    <div class="kpi-label">Clothing</div>
  </div>
  <div class="kpi-card c4">
    <div class="kpi-icon" style="background:rgba(46,139,87,.1)">🌍</div>
    <?php if($pf!==null): ?>
    <div class="kpi-val" data-count="<?=(int)$pf?>" data-suffix=""><?=(int)$pf?></div>
    <?php else: ?><div class="kpi-val">—</div><?php endif; ?>
    <div class="kpi-label">People Helped</div>
  </div>
  <div class="kpi-card c5">
    <div class="kpi-icon" style="background:rgba(247,37,133,.1)">🏅</div>
    <div class="kpi-val" data-count="<?=count($ai_badges)?>" data-suffix=""><?=count($ai_badges)?></div>
    <div class="kpi-label">Badges Earned</div>
  </div>
</div>

<!-- ══ AI SMART INSIGHTS ══ -->
<div class="ai-panel">
  <div class="ai-header">
    <div class="ai-header-icon">🤖</div>
    <div class="ai-header-text">
      <h3>AI Smart Insights</h3>
      <p><span class="ai-live-dot"></span>Personalised decisions from your data + platform trends</p>
    </div>
    <a href="schedule_donation.php" style="padding:7px 14px;background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:11px;font-weight:700;border-radius:20px;text-decoration:none;flex-shrink:0;border:1px solid rgba(255,255,255,.2)">📅 Schedule →</a>
  </div>
  <div class="ai-body">
    <?php $sug_show = !empty($ai_suggestions) ? array_slice($ai_suggestions,0,3) : [
      ['icon'=>'💡','text'=>'<strong>Keep donating!</strong> Your contributions create real impact. Make your first donation to unlock AI-personalised insights.'],
      ['icon'=>'📊','text'=>'<strong>Platform Insight:</strong> Food donations are most needed on weekends. Consider scheduling for Saturday or Sunday.'],
      ['icon'=>'🎯','text'=>'<strong>Pro Tip:</strong> Mark donations as "High" priority when food is very perishable — it gets 2x faster pickup.'],
    ]; ?>
    <?php foreach($sug_show as $s): ?>
    <div class="ai-sug"><span class="ai-sug-icon"><?=htmlspecialchars($s['icon']??'💡')?></span><span class="ai-sug-text"><?=$s['text']??''?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="ai-impact-strip">
    <div class="ai-imp"><div class="ai-imp-val"><?=$pf!==null?number_format($pf):'—'?></div><div class="ai-imp-lbl">People Fed</div></div>
    <div class="ai-imp"><div class="ai-imp-val"><?=$co2!==null?number_format($co2,1).'<small style="font-size:.65rem">kg</small>':'—'?></div><div class="ai-imp-lbl">CO₂ Saved</div></div>
    <div class="ai-imp"><div class="ai-imp-val"><?=$ecov!==null?'₹'.number_format($ecov):'—'?></div><div class="ai-imp-lbl">Economic Value</div></div>
  </div>
</div>

<!-- ══ AI DONATION ASSISTANT (CHATBOT) ══ -->
<div class="ai-chat-panel" id="chatPanel">
  <div class="ai-chat-header" onclick="toggleChat()">
    <div style="width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0">🤖</div>
    <h4>AI Donation Assistant</h4>
    <span id="chatToggleLabel">Ask me anything →</span>
  </div>
  <div id="chatBody" style="display:none">
    <div class="ai-chat-messages" id="chatMessages"></div>
    <div class="chat-quick" id="chatQuick">
      <button class="cq-btn" onclick="sendChat('What is my donation impact?')">💚 My impact</button>
      <button class="cq-btn" onclick="sendChat('What should I donate next?')">🎯 Recommend cause</button>
      <button class="cq-btn" onclick="sendChat('Show my monthly report')">📊 Monthly report</button>
      <button class="cq-btn" onclick="sendChat('Set up recurring donation')">📅 Schedule</button>
      <button class="cq-btn" onclick="sendChat('What are my badges?')">🏅 My badges</button>
    </div>
    <div class="chat-input-row">
      <input class="chat-input" id="chatInput" placeholder="Ask about your donations, impact, causes…" maxlength="300" autocomplete="off">
      <button class="chat-send" onclick="sendChat(document.getElementById('chatInput').value)">➤</button>
    </div>
  </div>
</div>
<!-- ══ ACTIVE DONATION TRACKING ══ -->
<?php if(!empty($active_arr)): ?>
<div class="sec-head">
  <h3>📍 Active Donations <span style="background:rgba(0,109,119,.1);color:var(--dt);font-size:11px;padding:2px 10px;border-radius:20px;font-weight:700"><?=count($active_arr)?> live</span></h3>
  <a href="track.php">View All →</a>
</div>
<div class="track-list">
<?php foreach(array_slice($active_arr,0,3) as $d):
  $sidx = stepIndexD($d['status'],$STATUS_STEPS);
  $p    = round((($sidx+1)/count($STATUS_STEPS))*100);
  $isF  = $d['type']==='Food';
  // AI ETA for this donation
  $eta  = [];
  try{ $eta = adhaar_ai()->predictETA((int)$d['id'],$isF?'food':'cloth'); }catch(Throwable $e){}
?>
<div class="track-card <?=in_array($d['status'],['delivered','picked_up'])?'delivered':($d['status']==='rejected'?'rejected':'')?>">
  <div class="track-top">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span class="track-don-id"><?=htmlspecialchars($d['don_id']??('#'.$d['id']))?></span>
      <span class="track-type <?=$isF?'food':'cloth'?>"><?=$isF?'🍱 Food':'👕 Clothes'?></span>
      <?php if(!empty($eta['eta_human'])): ?>
      <span style="font-size:10px;font-weight:700;background:#fff3e0;color:#92400e;padding:3px 10px;border-radius:20px">⏱ AI ETA: ~<?=htmlspecialchars($eta['eta_human'])?></span>
      <?php endif; ?>
    </div>
    <span class="pill <?=htmlspecialchars($d['status'])?>"><?=ucfirst(str_replace('_',' ',$d['status']))?></span>
  </div>
  <div class="track-meta">
    <span>📦 <?=htmlspecialchars($d['quantity']??'—')?></span>
    <?php if(!empty($d['priority'])): ?><span>🔥 <?=ucfirst($d['priority'])?> priority</span><?php endif; ?>
    <?php if(!empty($d['pickup_date'])): ?><span>📅 <?=htmlspecialchars($d['pickup_date'])?></span><?php endif; ?>
    <span>🕐 <?=date('d M Y',strtotime($d['created_at']))?></span>
  </div>
  <div class="prog-bar-wrap">
    <div class="prog-bar-track"><div class="prog-bar-fill" style="width:<?=$p?>%"></div></div>
    <div class="prog-pct"><?=$p?>% complete</div>
  </div>
  <div class="tl-steps">
    <?php foreach($STATUS_STEPS as $i=>$s):
      $done=$i<$sidx; $act=$i===$sidx; ?>
    <div class="tl-step <?=$done?'done':''?> <?=$act?'active':''?>">
      <div class="tl-dot"><?=$done?'✓':($act?$STATUS_ICONS[$i]:($i+1))?></div>
      <span class="tl-label"><?=$STATUS_LABELS[$i]?></span>
    </div>
    <?php if($i<count($STATUS_STEPS)-1): ?><div class="tl-line <?=$done?'done':''?>"></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
    <a href="../api/donation_receipt.php?id=<?=(int)$d['id']?>&type=<?=$isF?'food':'cloth'?>" target="_blank" class="receipt-link">🖨️ Receipt</a>
    <?php if(!empty($d['volunteer_email'])): ?>
    <span style="font-size:11px;color:var(--muted);padding:5px 0">🤝 Volunteer: <?=htmlspecialchars($d['volunteer_email'])?></span>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ SMART NEED MATCHING ══ -->
<?php if(!empty($ai_need) && !empty($active_arr)): ?>
<div class="sec-head"><h3>🎯 AI Need Matching</h3><a href="donate.php">Donate Again →</a></div>
<div class="need-card">
  <div style="font-size:12px;color:var(--muted);margin-bottom:12px">Your active donation matched to these beneficiaries:</div>
  <?php foreach(array_slice($ai_need,0,3) as $n): ?>
  <div class="need-row">
    <div class="need-pct"><?=(int)$n['match_pct']?>%</div>
    <div style="flex:1">
      <div class="need-org"><?=htmlspecialchars($n['name']??'SoulServe Network')?></div>
      <div class="need-desc"><?=htmlspecialchars($n['reason']??'')?>  — <?=htmlspecialchars($n['city']??'Local')?> · Urgency: <strong><?=htmlspecialchars($n['urgency']??'—')?></strong></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ PERSONALIZED CAUSES ══ -->
<?php if(!empty($ai_causes)): ?>
<div class="sec-head"><h3>🎯 Causes For You</h3><a href="donate.php">Donate Now →</a></div>
<div class="causes-grid">
  <?php foreach(array_slice($ai_causes,0,4) as $c): ?>
  <div class="cause-card">
    <span class="cause-urgency <?=htmlspecialchars($c['urgency']??'low')?>"><?=htmlspecialchars($c['urgency']??'low')?></span>
    <div class="cause-icon"><?=htmlspecialchars($c['icon']??'🎁')?></div>
    <div class="cause-title"><?=htmlspecialchars($c['title']??'')?></div>
    <div class="cause-desc"><?=htmlspecialchars($c['desc']??'')?></div>
    <a href="<?=htmlspecialchars($c['url']??'donate.php')?>" class="cause-btn"><?=htmlspecialchars($c['action']??'Donate')?> →</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ DONATE CTA (if no donations) ══ -->
<?php if($total===0): ?>
<div class="donate-cta">
  <div><h3>Make Your First Donation</h3><p>Donate surplus food or clothing — we pick it up, you change a life.</p></div>
  <a href="donate.php" class="donate-cta-btn">🎁 Donate Now →</a>
</div>
<?php endif; ?>

<!-- ══ MONTHLY IMPACT REPORT ══ -->
<?php if(!empty($ai_report)): ?>
<div class="sec-head"><h3>📊 Monthly Impact Report</h3></div>
<div class="report-card">
  <div class="report-month">📅 <?=htmlspecialchars($ai_report['month']??'')?></div>
  <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:6px"><?=htmlspecialchars($ai_report['rank_msg']??'')?></div>
  <div class="report-grid">
    <div><div class="rep-stat-val"><?=(int)($ai_report['food_count']??0)?></div><div class="rep-stat-lbl">Food</div></div>
    <div><div class="rep-stat-val"><?=(int)($ai_report['cloth_count']??0)?></div><div class="rep-stat-lbl">Clothing</div></div>
    <div><div class="rep-stat-val"><?=(int)($ai_report['people_fed']??0)?></div><div class="rep-stat-lbl">Fed</div></div>
    <div><div class="rep-stat-val">₹<?=number_format((int)($ai_report['eco_value']??0))?></div><div class="rep-stat-lbl">Value</div></div>
  </div>
  <div class="report-msg"><?=htmlspecialchars($ai_report['ai_summary']??'')?></div>
  <?php if(!empty($ai_report['badges_earned'])): ?>
  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach($ai_report['badges_earned'] as $b): ?>
    <span style="background:rgba(255,255,255,.15);border-radius:10px;padding:5px 12px;font-size:12px;font-weight:700;color:#fff"><?=$b['badge_emoji']?> <?=htmlspecialchars($b['badge_name'])?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ BADGES EARNED ══ -->
<div class="sec-head">
  <h3>🏅 Your Badges</h3>
  <a href="../pages/leaderboard.php">Leaderboard →</a>
</div>
<?php if(!empty($ai_badges)): ?>
<div class="badges-wrap">
  <?php foreach($ai_badges as $b): ?>
  <div class="badge-chip" title="<?=htmlspecialchars($b['badge_desc']??'')?>">
    <span class="badge-emoji"><?=htmlspecialchars($b['badge_emoji']?:'🏅')?></span>
    <span class="badge-name"><?=htmlspecialchars($b['badge_name'])?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="badges-wrap" style="background:#f9fbfa;border-radius:16px;padding:20px;justify-content:center;margin-bottom:24px">
  <span class="badge-empty">🌱 Make your first donation to earn your first badge!</span>
</div>
<?php endif; ?>

<!-- ══ SMART RECURRING SUGGESTION ══ -->
<?php if(!empty($ai_recurring) && ($ai_recurring['has_pattern']??false)): ?>
<div class="rec-card">
  <span class="rec-icon">📅</span>
  <div class="rec-text">
    <h4>AI Schedule Suggestion</h4>
    <p><?=htmlspecialchars($ai_recurring['suggestion']??'')?> (<?=(int)($ai_recurring['confidence']??0)?>% confidence)</p>
  </div>
  <a href="schedule_donation.php" class="rec-btn">Set Schedule →</a>
</div>
<?php endif; ?>

<!-- ══ DONATION HISTORY TABLE ══ -->
<?php if(!empty($recent)): ?>
<div class="sec-head"><h3>📋 Recent Donations</h3><a href="history.php">View All →</a></div>
<div class="don-table-wrap">
  <div class="don-table-scroll">
    <table class="don-table">
      <thead><tr><th>Donation ID</th><th>Type</th><th>Qty</th><th>Date</th><th>Status</th><th>Receipt</th></tr></thead>
      <tbody>
        <?php foreach(array_slice($recent,0,7) as $r): ?>
        <tr>
          <td><span class="don-id-badge"><?=htmlspecialchars($r['don_id']??('#'.$r['id']))?></span></td>
          <td><?=$r['type']==='Food'?'🍱 Food':'👕 Clothes'?></td>
          <td><?=htmlspecialchars($r['quantity']??'—')?></td>
          <td style="white-space:nowrap;color:var(--muted)"><?=date('d M Y',strtotime($r['created_at']))?></td>
          <td><span class="pill <?=htmlspecialchars($r['status'])?>"><?=ucfirst(str_replace('_',' ',$r['status']))?></span></td>
          <td><a href="../api/donation_receipt.php?id=<?=(int)$r['id']?>&type=<?=$r['type']==='Food'?'food':'cloth'?>" target="_blank" class="receipt-link" style="font-size:11px">🖨️</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ══ SHOP STRIP + AI PRODUCTS ══ -->
<div class="shop-strip">
  <div><h3>🛍️ Support Rural Artisans</h3><p>Every purchase empowers rural entrepreneurs directly.</p></div>
  <a href="../shop/shop.php" class="shop-strip-btn">Browse Shop →</a>
</div>
<?php if(!empty($ai_products)||!empty($featured)): ?>
<div class="sec-head">
  <h3><?=!empty($ai_products)?'✨ AI Recommended Products':'⭐ Featured Products'?></h3>
  <a href="../shop/shop.php">See All →</a>
</div>
<div class="pm-grid">
  <?php foreach(array_slice(!empty($ai_products)?$ai_products:$featured,0,4) as $p): $img=!empty($p['image1'])?image_url($p['image1']):null; ?>
  <div class="pm-card" onclick="location.href='../shop/product.php?id=<?=(int)$p['id']?>'">
    <?php if($img): ?><img data-src="<?=htmlspecialchars($img)?>" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" class="pm-img" alt="" loading="lazy">
    <?php else: ?><div class="pm-img-ph">🛍️</div><?php endif; ?>
    <div class="pm-body">
      <div class="pm-name"><?=htmlspecialchars($p['name'])?></div>
      <div class="pm-store">🏪 <?=htmlspecialchars($p['store_name']??'Shop')?></div>
      <div class="pm-price">₹<?=number_format((float)$p['price'],0)?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ HOW IT WORKS ══ -->
<div class="sec-head" style="margin-top:8px"><h3>⚙️ How Your Donation Works</h3></div>
<div class="how-grid">
  <div class="how-step"><div class="how-step-num">1</div><div class="how-step-icon">📝</div><h4>Submit</h4><p>Fill form with photo, quantity & address.</p></div>
  <div class="how-step"><div class="how-step-num">2</div><div class="how-step-icon">🤖</div><h4>AI Verify</h4><p>AI checks validity & auto-assigns best volunteer.</p></div>
  <div class="how-step"><div class="how-step-num">3</div><div class="how-step-icon">🚚</div><h4>Pickup</h4><p>Volunteer collects from your address with GPS.</p></div>
  <div class="how-step"><div class="how-step-num">4</div><div class="how-step-icon">🎉</div><h4>Impact</h4><p>Delivered with dignity. Photo proof sent to you.</p></div>
</div>

</div><!-- .page -->
</main>
</div><!-- .app -->

<div id="dashToast"></div>
<script src="../js/dashboard.js"></script>
<script defer src="../js/ai_chat.js"></script>
<script>
/* ── Lazy images ── */
document.querySelectorAll('img[data-src]').forEach(img=>{
  new IntersectionObserver(([e],o)=>{if(e.isIntersecting){img.src=img.dataset.src;img.removeAttribute('data-src');o.disconnect();}},{rootMargin:'200px'}).observe(img);
});
<?php if($success&&$don_id): ?>
window.addEventListener('load',()=>{ if(typeof showToast==='function') showToast('Donation <?=htmlspecialchars($don_id)?> submitted!','success',5000); });
<?php endif; ?>
/* ── AI Chatbot ── */
let chatOpen=false;
function toggleChat(){
  chatOpen=!chatOpen;
  document.getElementById('chatBody').style.display=chatOpen?'block':'none';
  document.getElementById('chatToggleLabel').textContent=chatOpen?'Click to minimize':'Ask me anything →';
  if(chatOpen&&document.getElementById('chatMessages').children.length===0){
    addChatMsg('👋 Hi <?=addslashes(htmlspecialchars($user['name']))?> ! I\'m your AI Donation Assistant.<br>I can help with your impact, causes, scheduling, badges and more. What do you need?','bot');
    document.getElementById('chatInput').focus();
  }
}
function addChatMsg(text,role){
  const wrap=document.getElementById('chatMessages');
  const d=document.createElement('div');
  d.className='chat-msg '+role;
  const icon=role==='bot'?'🤖':'👤';
  d.innerHTML=`<div class="chat-icon">${icon}</div><div class="chat-bubble">${text}</div>`;
  wrap.appendChild(d);
  wrap.scrollTop=wrap.scrollHeight;
}
function sendChat(q){
  q=q.trim();if(!q)return;
  document.getElementById('chatInput').value='';
  if(!chatOpen)toggleChat();
  addChatMsg(q,'user');
  document.getElementById('chatQuick').style.display='none';
  // Show typing
  const tw=document.getElementById('chatMessages');
  const td=document.createElement('div');
  td.className='chat-msg bot';td.id='chatTyping';
  td.innerHTML='<div class="chat-icon">🤖</div><div class="typing-dots"><span></span><span></span><span></span></div>';
  tw.appendChild(td);tw.scrollTop=tw.scrollHeight;
  fetch('../api/ai_assistant.php',{method:'POST',body:new URLSearchParams({message:q,context:'donor'})})
    .then(r=>r.json()).then(d=>{
      const t=document.getElementById('chatTyping');if(t)t.remove();
      addChatMsg(d.reply||'I could not process that.','bot');
    }).catch(()=>{
      const t=document.getElementById('chatTyping');if(t)t.remove();
      addChatMsg('Connection error. Please try again.','bot');
    });
}
document.getElementById('chatInput').addEventListener('keydown',e=>{if(e.key==='Enter')sendChat(e.target.value);});
</script>
</body>
</html>
