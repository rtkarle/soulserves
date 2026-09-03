<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
require_once __DIR__ . '/../api/ai_engine.php';
/* ── Role-based access guard ── */
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'volunteer') {
    switch ($_SESSION['role']) {
        case 'donor':   header("Location: ../donor/donor_dashboard.php");   exit;
        case 'seller':  header("Location: ../seller/seller_dashboard.php"); exit;
        case 'admin':   header("Location: ../admin/admin_dashboard.php");   exit;
    }
}
$email = $_SESSION['user_email'];
$q = $conn->prepare("SELECT * FROM register WHERE email=? AND role='volunteer' AND verified=1");
$q->bind_param("s",$email); $q->execute();
$res = $q->get_result();
if ($res->num_rows !== 1) {
    $chk = $conn->prepare("SELECT role FROM register WHERE email=? AND verified=1");
    $chk->bind_param("s",$email); $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if ($row) {
        $_SESSION['role'] = $row['role'];
        switch ($row['role']) {
            case 'donor':  header("Location: ../donor/donor_dashboard.php");   exit;
            case 'seller': header("Location: ../seller/seller_dashboard.php"); exit;
        }
    }
    header("Location: ../auth/login.php"); exit;
}
$user = $res->fetch_assoc();

/* ── POST: task accept/reject ── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['task_action'])){
    csrf_verify();
    $tid    = (int)$_POST['task_id'];
    $action = in_array($_POST['task_action'],['accepted','rejected']) ? $_POST['task_action'] : 'rejected';
    $tq = $conn->prepare("UPDATE volunteer_tasks SET task_status=?,responded_at=NOW() WHERE id=? AND volunteer_email=?");
    $tq->bind_param("sis",$action,$tid,$email); $tq->execute();
    header("Location: volunteer_dashboard.php?tab=tasks"); exit;
}

$tab = $_GET['tab'] ?? 'overview';
$me  = mysqli_real_escape_string($conn, $email);

/* ── AI Engine — session-cached (5-min TTL) ── */
$ai = adhaar_ai();
$ai_recs=$ai_route=$ai_workload=[];
try {
    $ai_recs     = ai_cached("vol_recs_{$email}",      300, fn()=> $ai->getVolunteerRecommendations($email) ?: []);
    $ai_route    = ai_cached("vol_route_{$email}",     300, fn()=> $ai->suggestPickupRoute($email) ?: []);
    $ai_workload = ai_cached("vol_workload_{$email}",  120, fn()=> $ai->checkVolunteerWorkload($email) ?: []);
} catch(Throwable $e){}

/* ── Data queries ── */
$assigned_food=$assigned_cloth=[];
try{
    $af=$conn->prepare("SELECT id,'Food' AS type,COALESCE(donation_id,CONCAT('DON-FOOD-',LPAD(id,6,'0'))) AS don_id,quantity,pickup_address,contact,status,created_at,image,donor_email,notes,priority FROM food_donations WHERE volunteer_email=? AND status NOT IN ('delivered','rejected') ORDER BY FIELD(priority,'high','medium','low'),created_at DESC");
    $af->bind_param("s",$email);$af->execute();$assigned_food=$af->get_result()->fetch_all(MYSQLI_ASSOC);
}catch(Throwable $e){}
try{
    $ac=$conn->prepare("SELECT id,'Cloth' AS type,COALESCE(donation_id,CONCAT('DON-CLO-',LPAD(id,6,'0'))) AS don_id,quantity,pickup_address,contact,status,created_at,image,donor_email,notes,NULL AS priority FROM cloth_donations WHERE volunteer_email=? AND status NOT IN ('delivered','rejected') ORDER BY created_at DESC");
    $ac->bind_param("s",$email);$ac->execute();$assigned_cloth=$ac->get_result()->fetch_all(MYSQLI_ASSOC);
}catch(Throwable $e){}
$assigned = array_merge($assigned_food,$assigned_cloth);

$comp_food=$comp_cloth=[];
try{$cf=$conn->prepare("SELECT id,'Food' AS type,quantity,pickup_address,status,created_at,donor_email FROM food_donations WHERE volunteer_email=? AND status='delivered' ORDER BY created_at DESC LIMIT 20");$cf->bind_param("s",$email);$cf->execute();$comp_food=$cf->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}
try{$cc=$conn->prepare("SELECT id,'Cloth' AS type,quantity,pickup_address,status,created_at,donor_email FROM cloth_donations WHERE volunteer_email=? AND status='delivered' ORDER BY created_at DESC LIMIT 20");$cc->bind_param("s",$email);$cc->execute();$comp_cloth=$cc->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}
$completed = array_merge($comp_food,$comp_cloth);
usort($completed, fn($a,$b)=>strtotime($b['created_at'])-strtotime($a['created_at']));

$pending_tasks=[];
try{$tq2=$conn->prepare("SELECT vt.* FROM volunteer_tasks vt WHERE vt.volunteer_email=? AND vt.task_status='pending_acceptance' ORDER BY vt.assigned_at DESC");$tq2->bind_param("s",$email);$tq2->execute();$pending_tasks=$tq2->get_result()->fetch_all(MYSQLI_ASSOC);}catch(Throwable $e){}

$peers=[];
try{$pvq=$conn->query("SELECT name,email,mobile,address FROM register WHERE role='volunteer' AND verified=1 AND email!='$me' ORDER BY name LIMIT 20");$peers=$pvq?$pvq->fetch_all(MYSQLI_ASSOC):[];}catch(Throwable $e){}

$cart_count  = (int)$conn->query("SELECT COUNT(*) c FROM cart WHERE user_email='$me'")->fetch_assoc()['c'];
$order_count = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE buyer_email='$me'")->fetch_assoc()['c'];

/* ── ETA for each assigned donation — cached per donation ── */
$etas = [];
foreach($assigned as $d){
    try{
        $dtype = $d['type']==='Food'?'food':'cloth';
        $etas[$d['id'].'_'.$dtype] = ai_cached(
            "vol_eta_{$d['id']}_{$dtype}", 180,
            fn()=> $ai->predictETA((int)$d['id'], $dtype)
        );
    }catch(Throwable $e){}
}

/* ── Volunteer badges ── */
require_once __DIR__ . '/../api/award_badges.php';
$vol_badges = [];
try{ $vol_badges = get_donor_badges($conn,$email); }catch(Throwable $e){}
// Award volunteer badges based on completions
$tot_done = count($completed);
$conn->query("CREATE TABLE IF NOT EXISTS donor_badges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, donor_email VARCHAR(180) NOT NULL,
    badge_key VARCHAR(60) NOT NULL, badge_name VARCHAR(100) NOT NULL,
    badge_emoji VARCHAR(8) NOT NULL DEFAULT '🏅', badge_desc VARCHAR(255),
    earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_donor_badge (donor_email, badge_key)
) ENGINE=InnoDB");
if($tot_done>=1){$conn->query("INSERT IGNORE INTO donor_badges (donor_email,badge_key,badge_name,badge_emoji,badge_desc) VALUES ('$me','vol_first','First Delivery','🌱','Completed your first delivery!')");}
if($tot_done>=10){$conn->query("INSERT IGNORE INTO donor_badges (donor_email,badge_key,badge_name,badge_emoji,badge_desc) VALUES ('$me','vol_ten','10 Deliveries','⭐','Delivered 10 times!')");}
if($tot_done>=25){$conn->query("INSERT IGNORE INTO donor_badges (donor_email,badge_key,badge_name,badge_emoji,badge_desc) VALUES ('$me','vol_champion','Volunteer Champion','🏆','25 deliveries completed!')");}
if($tot_done>=50){$conn->query("INSERT IGNORE INTO donor_badges (donor_email,badge_key,badge_name,badge_emoji,badge_desc) VALUES ('$me','vol_legend','SoulServe Legend','👑','50+ deliveries. You are legendary!')");}
$vol_badges = [];
try{$bq=$conn->query("SELECT * FROM donor_badges WHERE donor_email='$me' ORDER BY earned_at DESC");$vol_badges=$bq?$bq->fetch_all(MYSQLI_ASSOC):[];}catch(Throwable $e){}

/* ── impact score ── */
$impact_score = (int)($ai_workload['impact_score'] ?? 0);
$vol_level    = $ai_workload['level']       ?? 'Newcomer';
$vol_emoji    = $ai_workload['level_emoji'] ?? '🌱';
$comp_rate    = (int)($ai_workload['completion_rate'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Volunteer Dashboard — SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#102A43">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/dashboard.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
<style>
:root{--vt:#006D77;--vg:#2E8B57;--vo:#FF8A00;--vgr:linear-gradient(135deg,#006D77,#2E8B57);--vhr:linear-gradient(135deg,#102A43 0%,#006D77 55%,#2E8B57 100%)}
/* Welcome */
.vwb{background:var(--vhr);border-radius:24px;padding:28px 30px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;box-shadow:0 16px 48px rgba(0,109,119,.25);animation:fUp .5s ease}
.vwb::before{content:'';position:absolute;right:-50px;top:-50px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none}
.vwb-title{font-size:20px;font-weight:800;margin-bottom:4px}.vwb-sub{font-size:13px;opacity:.75}
.vwb-btns{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.vwb-btn{padding:9px 20px;border-radius:20px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;border:none;transition:.2s}
.vwb-btn.white{background:#fff;color:var(--vt)}.vwb-btn.white:hover{background:#e8fdf5;transform:translateY(-1px)}
.vwb-btn.glass{background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.3)}.vwb-btn.glass:hover{background:rgba(255,255,255,.25)}
/* KPIs */
.vkpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.vkpi{background:#fff;border-radius:18px;padding:18px 16px;box-shadow:0 4px 20px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s;position:relative;overflow:hidden}
.vkpi:hover{transform:translateY(-4px);box-shadow:0 12px 36px rgba(16,42,67,.12)}
.vkpi::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 18px 18px;opacity:0;transition:.3s}
.vkpi:hover::after{opacity:1}
.vkpi.c1::after{background:var(--vgr)}.vkpi.c2::after{background:linear-gradient(135deg,var(--vo),#F72585)}
.vkpi.c3::after{background:linear-gradient(135deg,#2563EB,#7B2CBF)}.vkpi.c4::after{background:linear-gradient(135deg,var(--vg),var(--vt))}
.vkpi.c5::after{background:linear-gradient(135deg,#F72585,var(--vo))}
.vkpi-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:10px}
.vkpi-val{font-size:28px;font-weight:900;color:var(--navy);line-height:1;margin-bottom:3px}
.vkpi-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
/* Impact score ring */
.impact-ring-wrap{display:flex;align-items:center;gap:20px;background:linear-gradient(135deg,#0a1f30,#0d3858 40%,#006d77 100%);border-radius:20px;padding:20px 24px;margin-bottom:24px;color:#fff}
.impact-ring{position:relative;width:90px;height:90px;flex-shrink:0}
.impact-ring svg{transform:rotate(-90deg)}
.impact-ring-num{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:#fff}
.impact-ring-num small{font-size:10px;font-weight:600;color:rgba(255,255,255,.6);margin-top:2px}
.impact-info h3{font-size:18px;font-weight:800;margin-bottom:4px}
.impact-info p{font-size:13px;color:rgba(255,255,255,.7);line-height:1.65}
.impact-level{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;margin-top:10px}
/* AI panel */
.ai-vol-panel{background:linear-gradient(135deg,#0a1f30,#0d3858 40%,#006d77 100%);border-radius:22px;padding:0;overflow:hidden;margin-bottom:24px;box-shadow:0 12px 40px rgba(0,109,119,.2)}
.ai-vol-header{padding:16px 22px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.1)}
.ai-vol-header-icon{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.ai-vol-header h3{font-size:15px;font-weight:800;color:#fff;margin-bottom:2px}
.ai-vol-header p{font-size:11px;color:rgba(255,255,255,.55)}
.ai-live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;animation:livP 1.6s ease infinite;margin-right:4px}
@keyframes livP{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}
.ai-vol-body{padding:16px 22px;display:flex;flex-direction:column;gap:9px}
.ai-vol-sug{display:flex;align-items:flex-start;gap:12px;padding:12px 15px;border-radius:13px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.08);transition:.2s}
.ai-vol-sug:hover{background:rgba(255,255,255,.12)}
.ai-vol-sug-icon{font-size:18px;flex-shrink:0;margin-top:1px}
.ai-vol-sug-text{font-size:13px;color:rgba(255,255,255,.85);line-height:1.65}.ai-vol-sug-text strong{color:#fff}
/* Route card */
.route-card{background:#fff;border-radius:18px;padding:20px;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);margin-bottom:24px}
.route-step{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid rgba(16,42,67,.05)}
.route-step:last-child{border-bottom:none}
.route-stop-num{width:32px;height:32px;border-radius:50%;background:var(--vgr);color:#fff;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.route-stop-type{font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;margin-bottom:4px}
.route-stop-type.food{background:#fef3c7;color:#92400e}.route-stop-type.cloth{background:#dbeafe;color:#1e40af}
.route-addr{font-size:13px;color:var(--navy);font-weight:600;margin-bottom:2px}
.route-reason{font-size:11px;color:var(--muted)}
.route-priority-badge{font-size:10px;font-weight:700;padding:2px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.3px}
.route-priority-badge.high{background:#fee2e2;color:#991b1b}
.route-priority-badge.medium{background:#fef3c7;color:#92400e}
.route-priority-badge.low{background:#d1fae5;color:#065f46}
/* Task cards */
.vtask-card{background:#fff;border-radius:16px;padding:18px 20px;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);margin-bottom:14px;border-left:4px solid var(--vo);animation:fUp .3s ease}
.vtask-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.vtask-id{font-size:12px;font-weight:800;color:var(--navy);background:rgba(0,109,119,.08);padding:3px 11px;border-radius:20px;font-family:'Inter',monospace}
.vtask-priority{font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase}
.vtask-meta{font-size:13px;color:var(--muted);line-height:1.75;margin-bottom:12px}
.vtask-actions{display:flex;gap:10px;flex-wrap:wrap}
.btn-accept{padding:9px 20px;border:none;border-radius:10px;background:#d1fae5;color:#065f46;font-size:13px;font-weight:700;cursor:pointer;transition:.2s}
.btn-accept:hover{background:#a7f3d0}
.btn-reject{padding:9px 20px;border:none;border-radius:10px;background:#fee2e2;color:#991b1b;font-size:13px;font-weight:700;cursor:pointer;transition:.2s}
.btn-reject:hover{background:#fca5a5}
/* Donation cards */
.vdon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:20px}
.vdon-card{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.3s}
.vdon-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(16,42,67,.1)}
.vdon-img{width:100%;height:160px;object-fit:cover;background:linear-gradient(135deg,#edf5f2,#eef2ff);display:flex;align-items:center;justify-content:center;font-size:48px}
.vdon-body{padding:16px}
.vdon-type{display:inline-block;font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;margin-bottom:10px}
.vdon-type.food{background:#fef3c7;color:#92400e}.vdon-type.cloth{background:#dbeafe;color:#1e40af}
.vdon-meta{font-size:13px;color:var(--muted);line-height:1.7;margin-bottom:12px}
.vdon-meta strong{color:var(--navy)}
.vdon-eta{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;background:#fff3e0;color:#92400e;padding:4px 10px;border-radius:20px;margin-bottom:10px}
.vdon-actions{display:flex;gap:8px;flex-wrap:wrap}
.action-btn{padding:9px 14px;border:none;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;transition:.2s;flex:1;min-width:110px;text-align:center}
.btn-pickup{background:#dbeafe;color:#1e40af}.btn-pickup:hover{background:#bfdbfe}
.btn-delivered{background:#d1fae5;color:#065f46}.btn-delivered:hover{background:#a7f3d0}
/* Pill */
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.pill.pending{background:#fef3c7;color:#92400e}.pill.accepted{background:#dbeafe;color:#1e40af}
.pill.scheduled{background:#ede9fe;color:#5b21b6}.pill.out_for_pickup{background:#fce7f3;color:#9d174d}
.pill.picked_up,.pill.delivered{background:#d1fae5;color:#065f46}.pill.rejected{background:#fee2e2;color:#991b1b}
/* Chatbot */
.vchat-panel{background:#fff;border-radius:22px;border:1px solid rgba(16,42,67,.06);box-shadow:0 4px 20px rgba(16,42,67,.07);overflow:hidden;margin-bottom:24px}
.vchat-header{background:linear-gradient(135deg,#102A43,#006D77);padding:14px 20px;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.vchat-header h4{font-size:14px;font-weight:800;color:#fff;margin:0;flex:1}
.vchat-msgs{height:240px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth;background:#f9fbfa}
.cmsg{display:flex;gap:8px;align-items:flex-end;animation:fUp .2s ease}
.cmsg.bot{flex-direction:row}.cmsg.user{flex-direction:row-reverse}
.cbub{max-width:80%;padding:10px 14px;border-radius:16px;font-size:13px;line-height:1.6}
.cmsg.bot .cbub{background:#fff;color:var(--navy);border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px;box-shadow:0 2px 8px rgba(16,42,67,.06)}
.cmsg.user .cbub{background:var(--vgr);color:#fff;border-radius:16px 4px 16px 16px}
.cico{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.cmsg.bot .cico{background:var(--vgr);color:#fff}.cmsg.user .cico{background:#e2ebe9;color:var(--navy)}
.cinput-row{display:flex;border-top:1px solid rgba(16,42,67,.08)}
.cinput{flex:1;padding:12px 16px;border:none;outline:none;font-size:13px;font-family:inherit;color:var(--navy);background:#fff}
.csend{padding:0 18px;background:var(--vgr);color:#fff;border:none;cursor:pointer;font-size:15px}
.cquick{padding:8px 14px 10px;display:flex;gap:6px;flex-wrap:wrap;background:#f9fbfa;border-top:1px solid rgba(16,42,67,.06)}
.cqb{padding:5px 12px;border-radius:20px;border:1.5px solid rgba(0,109,119,.2);background:#fff;font-size:11px;font-weight:600;color:var(--vt);cursor:pointer;transition:.2s;font-family:inherit}
.cqb:hover{background:var(--vt);color:#fff;border-color:var(--vt)}
.typing-dots{display:flex;gap:4px;align-items:center;padding:10px 14px;background:#fff;border:1px solid rgba(16,42,67,.08);border-radius:4px 16px 16px 16px;width:fit-content}
.typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:td 1.2s infinite}
.typing-dots span:nth-child(2){animation-delay:.2s}.typing-dots span:nth-child(3){animation-delay:.4s}
@keyframes td{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}
/* SOS */
.sos-btn{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;padding:13px 28px;border-radius:20px;font-size:14px;font-weight:800;cursor:pointer;transition:.2s;display:flex;align-items:center;gap:8px;box-shadow:0 4px 16px rgba(220,38,38,.3)}
.sos-btn:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(220,38,38,.5);animation:none}
/* Badges */
.vol-badges{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.vol-badge-chip{display:flex;align-items:center;gap:8px;padding:8px 14px;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);transition:.2s}
.vol-badge-chip:hover{transform:translateY(-2px)}
/* Sec head */
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.sec-head h3{font-size:15px;font-weight:800;color:var(--navy)}
.sec-head a,.sec-head button{font-size:12px;font-weight:700;color:var(--teal);text-decoration:none;padding:5px 13px;border-radius:20px;background:rgba(0,109,119,.08);border:none;cursor:pointer;transition:.2s}
.sec-head a:hover,.sec-head button:hover{background:rgba(0,109,119,.15)}
/* Map */
.vol-map{height:280px;border-radius:16px;overflow:hidden;border:1.5px solid rgba(16,42,67,.1);margin-top:16px;margin-bottom:20px}
/* Proof modal */
.proof-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:20px}
.proof-overlay.open{display:flex}
.proof-modal{background:#fff;border-radius:20px;padding:30px;max-width:480px;width:100%;box-shadow:0 24px 80px rgba(0,0,0,.28);animation:mIn .3s ease}
@keyframes mIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.proof-field{margin-bottom:16px}
.proof-field label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px}
.proof-field input,.proof-field textarea{width:100%;padding:11px 14px;border:1.5px solid #e2ebe9;border-radius:10px;font-size:13px;font-family:inherit;outline:none;transition:.2s}
.proof-field input:focus,.proof-field textarea:focus{border-color:var(--teal)}
.proof-submit{width:100%;padding:13px;background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;transition:.25s;margin-top:6px}
.proof-cancel{width:100%;padding:10px;background:#f0f4f3;color:var(--muted);border:none;border-radius:12px;font-size:13px;font-weight:700;cursor:pointer;margin-top:8px}
/* Peer grid */
.peer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
.peer-card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 4px 14px rgba(16,42,67,.07);border:1px solid rgba(16,42,67,.06);border-top:3px solid var(--vt);transition:.3s}
.peer-card:hover{transform:translateY(-4px)}
.peer-avatar{width:44px;height:44px;border-radius:50%;background:var(--vgr);display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px;font-weight:800;margin-bottom:10px}
/* Empty */
.empty-state{background:#f9fbfa;border:1px dashed rgba(16,42,67,.12);border-radius:16px;padding:40px;text-align:center;color:var(--muted)}
.empty-state .emoji{font-size:40px;display:block;margin-bottom:12px}
/* Responsive */
@media(max-width:1200px){.vkpi-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .vkpi-row{grid-template-columns:repeat(3,1fr)}
  .vdon-grid{grid-template-columns:1fr 1fr}
  .peer-grid{grid-template-columns:repeat(2,1fr)}
  .impact-ring-wrap{gap:16px}
  .vwb{padding:20px 22px;border-radius:18px}
}
@media(max-width:700px){
  .vkpi-row{grid-template-columns:repeat(2,1fr);gap:10px}
  .vdon-grid{grid-template-columns:1fr}
  .peer-grid{grid-template-columns:repeat(2,1fr)}
  .page{padding:14px}
  .vwb{padding:16px;border-radius:16px}
  .vwb-title{font-size:17px}
  .route-card{padding:14px 16px}
  .vtask-card{padding:14px 16px}
  .vol-map{height:220px}
  .ai-vol-body{padding:12px 16px;gap:8px}
  .vchat-msgs{height:200px}
  .ai-vol-panel{border-radius:16px}
}
@media(max-width:480px){
  .vkpi-row{grid-template-columns:1fr 1fr;gap:8px}
  .vkpi-val{font-size:22px}
  .vkpi-icon{width:36px;height:36px;font-size:17px;margin-bottom:8px}
  .vdon-grid{grid-template-columns:1fr}
  .peer-grid{grid-template-columns:1fr}
  .impact-ring-wrap{flex-direction:column;text-align:center;padding:16px}
  .impact-ring{width:80px;height:80px}
  .impact-ring-num{font-size:20px}
  .impact-info h3{font-size:16px}
  .impact-info p{font-size:12px}
  .page{padding:12px}
  .vwb{padding:14px;border-radius:14px}
  .vwb-title{font-size:16px}
  .vwb-btns{gap:6px}
  .vwb-btn{padding:7px 13px;font-size:12px}
  .vtask-actions{flex-direction:column}
  .btn-accept,.btn-reject{width:100%;text-align:center}
  .route-step{gap:8px}
  .vol-map{height:180px}
  .vchat-msgs{height:180px}
  .sec-head h3{font-size:13px}
  .vol-badge-chip{padding:6px 10px}
}
@keyframes fUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
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
    <div class="sidebar-logo-text"><strong>SoulServe</strong><span>Volunteer Portal</span></div>
  </div>
  <div style="margin:8px 10px 4px;padding:12px 14px;background:rgba(255,255,255,.06);border-radius:12px;border:1px solid rgba(255,255,255,.08)">
    <div style="width:36px;height:36px;border-radius:50%;background:var(--gradient-teal);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;font-weight:800;margin-bottom:8px"><?=strtoupper(substr($user['name'],0,1))?></div>
    <div style="font-size:13px;font-weight:700;color:#fff"><?=htmlspecialchars($user['name'])?></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:2px"><?=$vol_emoji?> <?=$vol_level?> · Score <?=$impact_score?>/100</div>
  </div>
  <div class="nav-sec">Pickups</div>
  <button class="nav-btn <?=$tab==='overview'?'active':''?>" data-tab="overview" onclick="openTab('overview')">🏠 Overview</button>
  <button class="nav-btn <?=$tab==='assigned'?'active':''?>" data-tab="assigned" onclick="openTab('assigned')">📦 Assigned<?php if(count($assigned)>0):?><span class="nav-badge green"><?=count($assigned)?></span><?php endif;?></button>
  <button class="nav-btn <?=$tab==='tasks'?'active':''?>" data-tab="tasks" onclick="openTab('tasks')">📋 Task Requests<?php if(count($pending_tasks)>0):?><span class="nav-badge"><?=count($pending_tasks)?></span><?php endif;?></button>
  <button class="nav-btn <?=$tab==='route'?'active':''?>" data-tab="route" onclick="openTab('route')">🗺️ AI Route</button>
  <button class="nav-btn <?=$tab==='completed'?'active':''?>" data-tab="completed" onclick="openTab('completed')">✅ Completed (<?=count($completed)?>)</button>
  <div class="nav-sec">Leaderboard</div>
  <a href="../pages/leaderboard.php" class="nav-btn">🏆 Leaderboard</a>
  <button class="nav-btn <?=$tab==='peers'?'active':''?>" data-tab="peers" onclick="openTab('peers')">👥 Volunteers</button>
  <div class="nav-sec">Shop</div>
  <a href="../shop/shop.php"      class="nav-btn">🛍️ Browse Shop</a>
  <a href="../shop/cart.php"      class="nav-btn">🛒 My Cart<?php if($cart_count>0):?><span class="nav-badge"><?=$cart_count?></span><?php endif;?></a>
  <a href="../shop/my_orders.php" class="nav-btn">📦 My Orders<?php if($order_count>0):?><span class="nav-badge green"><?=$order_count?></span><?php endif;?></a>
  <div class="nav-sec">Account</div>
  <button class="nav-btn <?=$tab==='profile'?'active':''?>" data-tab="profile" onclick="openTab('profile')">👤 Profile</button>
  <div class="sidebar-footer"><a href="../auth/logout.php" class="logout-link">⇦ Logout</a></div>
</aside>

<!-- MAIN -->
<main class="main">
<div class="page">

<!-- ══ TAB: OVERVIEW ══ -->
<div id="tab-overview" class="tab-panel <?=$tab==='overview'?'active':''?>">

<!-- Welcome band -->
<div class="vwb">
  <div style="position:relative;z-index:1">
    <div class="vwb-title"><span id="greetTime">Hello</span>, <?=htmlspecialchars($user['name'])?> 🤝</div>
    <div class="vwb-sub"><?=count($assigned)?> active · <?=count($completed)?> completed · Impact Score <?=$impact_score?>/100</div>
  </div>
  <div class="vwb-btns">
    <button class="vwb-btn white" onclick="openTab('assigned')">📦 Active Tasks</button>
    <button class="vwb-btn glass" onclick="openTab('route')">🗺️ AI Route</button>
    <button class="vwb-btn glass" onclick="document.getElementById('sosModal').style.display='flex'">🆘 SOS</button>
  </div>
</div>

<!-- KPIs -->
<div class="vkpi-row">
  <div class="vkpi c1">
    <div class="vkpi-icon" style="background:rgba(0,109,119,.1)">📦</div>
    <div class="vkpi-val" data-count="<?=count($assigned)?>" data-suffix=""><?=count($assigned)?></div>
    <div class="vkpi-label">Active Pickups</div>
  </div>
  <div class="vkpi c2">
    <div class="vkpi-icon" style="background:rgba(255,138,0,.1)">✅</div>
    <div class="vkpi-val" data-count="<?=count($completed)?>" data-suffix=""><?=count($completed)?></div>
    <div class="vkpi-label">Delivered</div>
  </div>
  <div class="vkpi c3">
    <div class="vkpi-icon" style="background:rgba(37,99,235,.1)">📋</div>
    <div class="vkpi-val" data-count="<?=count($pending_tasks)?>" data-suffix=""><?=count($pending_tasks)?></div>
    <div class="vkpi-label">Pending Tasks</div>
  </div>
  <div class="vkpi c4">
    <div class="vkpi-icon" style="background:rgba(46,139,87,.1)">📈</div>
    <div class="vkpi-val"><?=$comp_rate?>%</div>
    <div class="vkpi-label">Accept Rate</div>
  </div>
  <div class="vkpi c5">
    <div class="vkpi-icon" style="background:rgba(247,37,133,.1)">🏅</div>
    <div class="vkpi-val" data-count="<?=count($vol_badges)?>" data-suffix=""><?=count($vol_badges)?></div>
    <div class="vkpi-label">Badges</div>
  </div>
</div>

<!-- Impact Score Ring -->
<div class="impact-ring-wrap">
  <div class="impact-ring">
    <svg width="90" height="90" viewBox="0 0 90 90">
      <circle cx="45" cy="45" r="38" fill="none" stroke="rgba(255,255,255,.15)" stroke-width="8"/>
      <circle cx="45" cy="45" r="38" fill="none" stroke="#10b981" stroke-width="8"
        stroke-dasharray="<?=round(238.76*$impact_score/100)?> 238.76"
        stroke-linecap="round"/>
    </svg>
    <div class="impact-ring-num"><?=$impact_score?><small>/100</small></div>
  </div>
  <div class="impact-info">
    <h3><?=$vol_emoji?> <?=$vol_level?></h3>
    <p><?=htmlspecialchars($ai_workload['advice']??'Keep delivering to increase your impact score!')?></p>
    <div class="impact-level"><?=$vol_emoji?> <?=$vol_level?> · <?=count($completed)?> deliveries</div>
  </div>
</div>

<!-- AI Volunteer Guide -->
<div class="ai-vol-panel">
  <div class="ai-vol-header">
    <div class="ai-vol-header-icon">🤖</div>
    <div>
      <h3>AI Volunteer Intelligence</h3>
      <p><span class="ai-live-dot"></span>Smart guidance based on your workload, tasks & performance</p>
    </div>
    <button onclick="openTab('route')" style="padding:7px 14px;background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:11px;font-weight:700;border-radius:20px;border:1px solid rgba(255,255,255,.2);cursor:pointer;white-space:nowrap;flex-shrink:0">🗺️ Route →</button>
  </div>
  <div class="ai-vol-body">
    <?php $recs = !empty($ai_recs) ? $ai_recs : [
      ['icon'=>'📦','text'=>'<strong>Ready for pickups!</strong> Your queue is clear. Accept new tasks to build your impact score.'],
      ['icon'=>'🎯','text'=>'<strong>Pro Tip:</strong> Always pick up high-priority (🔴) food donations first — they are time-sensitive.'],
      ['icon'=>'📸','text'=>'<strong>Proof of Delivery:</strong> Always upload a clear photo when marking delivered. This builds donor trust and your reliability score.'],
    ]; foreach(array_slice($recs,0,3) as $r): ?>
    <div class="ai-vol-sug">
      <span class="ai-vol-sug-icon"><?=htmlspecialchars($r['icon']??'💡')?></span>
      <span class="ai-vol-sug-text"><?=$r['text']??''?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Volunteer Badges -->
<div class="sec-head"><h3>🏅 Your Badges</h3><a href="../pages/leaderboard.php">Leaderboard →</a></div>
<?php if(!empty($vol_badges)): ?>
<div class="vol-badges">
  <?php foreach($vol_badges as $b): ?>
  <div class="vol-badge-chip" title="<?=htmlspecialchars($b['badge_desc']??'')?>">
    <span style="font-size:20px"><?=htmlspecialchars($b['badge_emoji']?:'🏅')?></span>
    <span style="font-size:12px;font-weight:700;color:var(--navy)"><?=htmlspecialchars($b['badge_name'])?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="vol-badges" style="background:#f9fbfa;border-radius:16px;padding:16px;margin-bottom:24px">
  <span style="font-size:13px;color:var(--muted);font-style:italic">🌱 Complete your first delivery to earn your first badge!</span>
</div>
<?php endif; ?>

<!-- AI Chatbot -->
<div class="vchat-panel">
  <div class="vchat-header" onclick="toggleVChat()">
    <div style="width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#FF8A00,#F72585);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0">🤖</div>
    <h4>AI Volunteer Assistant</h4>
    <span id="vchatLabel" style="font-size:11px;color:rgba(255,255,255,.6)">Ask me anything →</span>
  </div>
  <div id="vchatBody" style="display:none">
    <div class="vchat-msgs" id="vchatMessages"></div>
    <div class="cquick">
      <button class="cqb" onclick="vChat('What are my active tasks?')">📦 My tasks</button>
      <button class="cqb" onclick="vChat('Suggest my pickup route')">🗺️ Route</button>
      <button class="cqb" onclick="vChat('Show my performance stats')">📊 Performance</button>
      <button class="cqb" onclick="vChat('Emergency SOS help')">🆘 SOS</button>
    </div>
    <div class="cinput-row">
      <input class="cinput" id="vchatInput" placeholder="Ask about tasks, route, performance…" maxlength="300" autocomplete="off">
      <button class="csend" onclick="vChat(document.getElementById('vchatInput').value)">➤</button>
    </div>
  </div>
</div>

</div><!-- /tab-overview -->

<!-- ══ TAB: ASSIGNED ══ -->
<div id="tab-assigned" class="tab-panel <?=$tab==='assigned'?'active':''?>">
<div class="sec-head">
  <h3>📦 Active Pickups (<?=count($assigned)?>)</h3>
  <button onclick="openTab('route')">🗺️ AI Route</button>
</div>
<?php if(empty($assigned)): ?>
<div class="empty-state"><span class="emoji">📭</span><p>No active pickups. Check Task Requests for new assignments!</p></div>
<?php else: ?>
<div class="vdon-grid">
<?php foreach($assigned as $d):
  $tbl = ($d['type']==='Food') ? 'food_donations' : 'cloth_donations';
  $img = !empty($d['image']) ? image_url($d['image']) : null;
  $eta_key = $d['id'].'_'.strtolower($d['type']);
  $eta = $etas[$eta_key] ?? [];
  $pri = $d['priority'] ?? null;
?>
<div class="vdon-card">
  <?php if($img): ?><img src="<?=htmlspecialchars($img)?>" alt="" class="vdon-img" loading="lazy">
  <?php else: ?><div class="vdon-img"><?=$d['type']==='Food'?'🍱':'👕'?></div><?php endif; ?>
  <div class="vdon-body">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
      <span class="vdon-type <?=$d['type']==='Food'?'food':'cloth'?>"><?=$d['type']?></span>
      <?php if($pri): ?><span style="font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;<?=$pri==='high'?'background:#fee2e2;color:#991b1b':($pri==='medium'?'background:#fef3c7;color:#92400e':'background:#d1fae5;color:#065f46')?>"><?=$pri?> priority</span><?php endif; ?>
      <?php if(!empty($d['don_id'])): ?><span style="font-size:10px;font-weight:700;background:rgba(0,109,119,.08);color:var(--teal);padding:3px 9px;border-radius:20px;font-family:monospace"><?=htmlspecialchars($d['don_id'])?></span><?php endif; ?>
    </div>
    <?php if(!empty($eta['eta_human'])): ?>
    <div class="vdon-eta">⏱ AI ETA: ~<?=htmlspecialchars($eta['eta_human'])?> (<?=(int)($eta['confidence']??0)?>% confidence)</div>
    <?php endif; ?>
    <div class="vdon-meta">
      <strong>📦</strong> Qty: <?=htmlspecialchars($d['quantity']??'—')?><br>
      <strong>📍</strong> <?=htmlspecialchars($d['pickup_address']??'—')?><br>
      <strong>📞</strong> <?=htmlspecialchars($d['contact']??'—')?><br>
      <strong>Status:</strong> <span class="pill <?=htmlspecialchars($d['status'])?>"><?=ucfirst(str_replace('_',' ',$d['status']))?></span>
    </div>
    <div class="vdon-actions">
      <form method="POST" action="../api/update_status.php" style="flex:1">
        <?=csrf_field()?>
        <input type="hidden" name="id" value="<?=(int)$d['id']?>">
        <input type="hidden" name="table" value="<?=$tbl?>">
        <input type="hidden" name="status" value="picked_up">
        <button type="submit" class="action-btn btn-pickup">📦 Picked Up</button>
      </form>
      <button type="button" class="action-btn btn-delivered" onclick="openProofModal(<?=(int)$d['id']?>,'<?=$tbl?>','<?=htmlspecialchars(addslashes($d['type']))?>')">✅ Delivered</button>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- ══ TAB: AI ROUTE ══ -->
<div id="tab-route" class="tab-panel <?=$tab==='route'?'active':''?>">
<div class="sec-head"><h3>🗺️ AI Optimized Route</h3></div>
<?php if(empty($ai_route['route'])): ?>
<div class="empty-state"><span class="emoji">🗺️</span><p>No active tasks to route. Accept pickups first!</p></div>
<?php else: ?>
<div style="background:linear-gradient(135deg,rgba(0,109,119,.06),rgba(46,139,87,.04));border:1.5px solid rgba(0,109,119,.15);border-radius:18px;padding:16px 20px;margin-bottom:16px">
  <div style="font-size:13px;font-weight:700;color:var(--navy)">💡 <?=htmlspecialchars($ai_route['tip']??'')?></div>
</div>
<div class="route-card">
  <?php foreach($ai_route['route'] as $r): ?>
  <div class="route-step">
    <div class="route-stop-num"><?=(int)$r['stop']?></div>
    <div style="flex:1">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px">
        <span class="route-stop-type <?=$r['type']??'food'?>"><?=$r['type']==='food'?'🍱 Food':'👕 Cloth'?></span>
        <span class="route-priority-badge <?=$r['priority']??'low'?>"><?=$r['priority']??'medium'?></span>
      </div>
      <div class="route-addr"><?=htmlspecialchars($r['address']??'—')?></div>
      <div class="route-reason"><?=htmlspecialchars($r['reason']??'')?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<!-- Map placeholder -->
<div id="volRouteMap" class="vol-map" style="background:var(--soft-bg);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px">
  <div style="text-align:center"><div style="font-size:32px;margin-bottom:8px">📍</div>Enable location to see live map</div>
</div>
<?php endif; ?>
</div>

<!-- ══ TAB: TASK REQUESTS ══ -->
<div id="tab-tasks" class="tab-panel <?=$tab==='tasks'?'active':''?>">
<div class="sec-head"><h3>📋 Task Requests (<?=count($pending_tasks)?>)</h3></div>
<?php if(empty($pending_tasks)): ?>
<div class="empty-state"><span class="emoji">✅</span><p>No pending task requests. You're all caught up!</p></div>
<?php else: foreach($pending_tasks as $t): ?>
<div class="vtask-card">
  <div class="vtask-head">
    <span class="vtask-id">TASK-<?=(int)$t['id']?></span>
    <span class="vtask-priority" style="background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase"><?=ucfirst($t['donation_type'])?> Pickup</span>
  </div>
  <div class="vtask-meta">
    <strong>📦 Type:</strong> <?=ucfirst($t['donation_type'])?> Donation<br>
    <strong>🔑 Donation ID:</strong> #<?=(int)$t['donation_id']?><br>
    <strong>🕐 Assigned:</strong> <?=date('d M Y · h:i A',strtotime($t['assigned_at']))?><br>
    <?php if($t['notes']): ?><strong>📝 Notes:</strong> <?=htmlspecialchars($t['notes'])?><?php endif; ?>
  </div>
  <div class="vtask-actions">
    <form method="POST">
      <?=csrf_field()?><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><input type="hidden" name="task_action" value="accepted">
      <button type="submit" class="btn-accept">✓ Accept Task</button>
    </form>
    <form method="POST">
      <?=csrf_field()?><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><input type="hidden" name="task_action" value="rejected">
      <button type="submit" class="btn-reject">✗ Decline</button>
    </form>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- ══ TAB: COMPLETED ══ -->
<div id="tab-completed" class="tab-panel <?=$tab==='completed'?'active':''?>">
<div class="sec-head"><h3>✅ Completed Deliveries (<?=count($completed)?>)</h3></div>
<?php if(empty($completed)): ?>
<div class="empty-state"><span class="emoji">🏆</span><p>No completed deliveries yet. Accept your first task!</p></div>
<?php else: ?>
<div class="vdon-grid">
<?php foreach(array_slice($completed,0,12) as $c): ?>
<div class="vdon-card">
  <div class="vdon-img"><?=$c['type']==='Food'?'🍱':'👕'?></div>
  <div class="vdon-body">
    <span class="vdon-type <?=$c['type']==='Food'?'food':'cloth'?>"><?=$c['type']?></span>
    <div class="vdon-meta">
      <strong>📦</strong> Qty: <?=htmlspecialchars($c['quantity']??'—')?><br>
      <strong>📍</strong> <?=htmlspecialchars(mb_substr($c['pickup_address']??'—',0,60))?><br>
      <strong>🕐</strong> <?=date('d M Y',strtotime($c['created_at']))?><br>
      <span class="pill delivered">✓ Delivered</span>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- ══ TAB: PEERS ══ -->
<div id="tab-peers" class="tab-panel <?=$tab==='peers'?'active':''?>">
<div class="sec-head"><h3>👥 Fellow Volunteers (<?=count($peers)?>)</h3></div>
<?php if(empty($peers)): ?>
<div class="empty-state"><span class="emoji">👥</span><p>No other volunteers yet.</p></div>
<?php else: ?>
<div class="peer-grid">
<?php foreach($peers as $pv): ?>
<div class="peer-card">
  <div class="peer-avatar"><?=strtoupper(substr($pv['name'],0,1))?></div>
  <div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:5px"><?=htmlspecialchars($pv['name'])?></div>
  <div style="font-size:12px;color:var(--muted);line-height:1.65">
    📧 <?=htmlspecialchars($pv['email'])?><br>
    <?php if($pv['mobile']): ?>📞 <?=htmlspecialchars($pv['mobile'])?><br><?php endif; ?>
    <?php if($pv['address']): ?>📍 <?=htmlspecialchars(mb_substr($pv['address'],0,50))?><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- ══ TAB: PROFILE ══ -->
<div id="tab-profile" class="tab-panel <?=$tab==='profile'?'active':''?>">
<div class="sec-head"><h3>👤 My Profile</h3></div>
<div class="profile-card">
  <div class="profile-avatar"><?=strtoupper(substr($user['name'],0,1))?></div>
  <div class="profile-row"><label>Name</label><p><?=htmlspecialchars($user['name'])?></p></div>
  <div class="profile-row"><label>Email</label><p><?=htmlspecialchars($user['email'])?></p></div>
  <div class="profile-row"><label>Mobile</label><p><?=htmlspecialchars($user['mobile']??'—')?></p></div>
  <?php if($user['address']): ?><div class="profile-row"><label>Address</label><p><?=htmlspecialchars($user['address'])?></p></div><?php endif; ?>
  <?php if($user['volunteer_reason']): ?><div class="profile-row"><label>Why I Volunteer</label><p><?=htmlspecialchars($user['volunteer_reason'])?></p></div><?php endif; ?>
  <div class="profile-row"><label>Impact Level</label><p><?=$vol_emoji?> <?=$vol_level?> (Score: <?=$impact_score?>/100)</p></div>
  <a href="../donor/edit_profile.php" style="display:inline-block;margin-top:16px;padding:11px 24px;background:var(--vgr);color:#fff;border-radius:10px;font-weight:700;font-size:14px;text-decoration:none">Edit Profile</a>
</div>
</div>

</div><!-- .page -->
</main>
</div><!-- .app -->

<!-- SOS Modal -->
<div id="sosModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:20px;padding:32px;max-width:440px;width:100%;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:mIn .3s ease">
    <div style="font-size:40px;text-align:center;margin-bottom:14px">🆘</div>
    <h3 style="font-size:20px;font-weight:800;color:#dc2626;text-align:center;margin-bottom:10px">Emergency / SOS</h3>
    <p style="font-size:14px;color:var(--muted);text-align:center;line-height:1.7;margin-bottom:22px">If you're in danger or have an emergency during a pickup, contact us immediately.</p>
    <div style="display:flex;flex-direction:column;gap:12px">
      <a href="tel:+918237917354" style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:#fee2e2;border-radius:14px;font-weight:700;color:#dc2626;text-decoration:none;font-size:14px">📞 Call Admin: +91 82379 17354</a>
      <a href="mailto:adhaarsoulserve@gmail.com" style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:#fef3c7;border-radius:14px;font-weight:700;color:#92400e;text-decoration:none;font-size:14px">📧 adhaarsoulserve@gmail.com</a>
    </div>
    <p style="font-size:12px;color:var(--muted);text-align:center;margin-top:16px;line-height:1.6">Your safety is our priority. Stop, move to safety, then call. 💙</p>
    <button onclick="document.getElementById('sosModal').style.display='none'" style="width:100%;margin-top:16px;padding:12px;background:#f0f4f3;border:none;border-radius:12px;font-size:13px;font-weight:700;cursor:pointer;color:var(--muted)">Close</button>
  </div>
</div>

<!-- Proof Modal -->
<div class="proof-overlay" id="proofOverlay">
  <div class="proof-modal">
    <h3 style="font-size:18px;font-weight:800;margin-bottom:6px">✅ Mark as Delivered</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.6">Upload a photo proof. The donor gets an email with your photo. 📧</p>
    <form method="POST" action="../api/update_status.php" enctype="multipart/form-data" id="proofForm">
      <?=csrf_field()?>
      <input type="hidden" name="id" id="proofDonId">
      <input type="hidden" name="table" id="proofTable">
      <input type="hidden" name="status" value="delivered">
      <div class="proof-field">
        <label>📸 Delivery Proof Photo *</label>
        <input type="file" name="delivery_proof" id="proofFile" accept="image/*" required onchange="previewProof(this)">
        <div id="proofPreviewWrap" style="display:none;margin-top:10px;border-radius:10px;overflow:hidden;border:2px solid #e2ebe9">
          <img id="proofThumb" src="" style="width:100%;max-height:180px;object-fit:cover;display:block">
        </div>
      </div>
      <div class="proof-field"><label>👥 Beneficiaries (optional)</label><input type="number" name="beneficiary_count" min="1" placeholder="e.g. 5 families"></div>
      <div class="proof-field"><label>📝 Delivery Note (optional)</label><textarea name="delivery_note" rows="2" placeholder="e.g. Delivered to Mr. Sharma at community hall"></textarea></div>
      <div id="proofTypeLabel" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:11px 14px;font-size:13px;font-weight:600;color:#065f46;margin-bottom:16px">📦 Donation info</div>
      <button type="submit" class="proof-submit">✅ Confirm Delivery</button>
      <button type="button" class="proof-cancel" onclick="document.getElementById('proofOverlay').classList.remove('open')">Cancel</button>
    </form>
  </div>
</div>

<div id="dashToast"></div>
<script src="../js/dashboard.js"></script>
<script defer src="../js/ai_chat.js"></script>
<script>
function openTab(t){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab===t));
  const p=document.getElementById('tab-'+t);if(p)p.classList.add('active');
  history.replaceState(null,'','?tab='+t);
}
function openProofModal(id,table,type){
  document.getElementById('proofDonId').value=id;
  document.getElementById('proofTable').value=table;
  document.getElementById('proofTypeLabel').textContent='📦 '+type+' Donation — ID #'+id;
  document.getElementById('proofFile').value='';
  document.getElementById('proofPreviewWrap').style.display='none';
  document.getElementById('proofOverlay').classList.add('open');
}
function previewProof(inp){
  if(inp.files&&inp.files[0]){const r=new FileReader();r.onload=e=>{document.getElementById('proofThumb').src=e.target.result;document.getElementById('proofPreviewWrap').style.display='block';};r.readAsDataURL(inp.files[0]);}
}
document.getElementById('proofOverlay').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
/* Volunteer AI Chat */
let vcOpen=false;
function toggleVChat(){
  vcOpen=!vcOpen;
  document.getElementById('vchatBody').style.display=vcOpen?'block':'none';
  document.getElementById('vchatLabel').textContent=vcOpen?'Click to minimize':'Ask me anything →';
  if(vcOpen&&document.getElementById('vchatMessages').children.length===0){
    addVMsg('🤝 Hi <?=addslashes(htmlspecialchars($user['name']))?> ! I\'m your Volunteer AI Assistant.<br>Ask me about your tasks, route, performance, or emergency help.','bot');
    document.getElementById('vchatInput').focus();
  }
}
function addVMsg(text,role){
  const w=document.getElementById('vchatMessages');
  const d=document.createElement('div');d.className='cmsg '+role;
  d.innerHTML=`<div class="cico">${role==='bot'?'🤖':'👤'}</div><div class="cbub">${text}</div>`;
  w.appendChild(d);w.scrollTop=w.scrollHeight;
}
function vChat(q){
  q=q.trim();if(!q)return;
  document.getElementById('vchatInput').value='';
  if(!vcOpen)toggleVChat();
  addVMsg(q,'user');
  document.getElementById('vchatBody').querySelector('.cquick').style.display='none';
  const w=document.getElementById('vchatMessages');
  const td=document.createElement('div');td.className='cmsg bot';td.id='vtyping';
  td.innerHTML='<div class="cico">🤖</div><div class="typing-dots"><span></span><span></span><span></span></div>';
  w.appendChild(td);w.scrollTop=w.scrollHeight;
  fetch('../api/ai_assistant.php',{method:'POST',body:new URLSearchParams({message:q,context:'volunteer'})})
    .then(r=>r.json()).then(d=>{document.getElementById('vtyping')?.remove();addVMsg(d.reply||'Could not process.','bot');})
    .catch(()=>{document.getElementById('vtyping')?.remove();addVMsg('Connection error. Try again.','bot');});
}
document.getElementById('vchatInput').addEventListener('keydown',e=>{if(e.key==='Enter')vChat(e.target.value);});
/* GPS location share for active pickups */
if(navigator.geolocation && <?=count($assigned)?> > 0){
  navigator.geolocation.watchPosition(pos=>{
    fetch('../api/update_location.php',{method:'POST',body:new URLSearchParams({
      lat:pos.coords.latitude,lng:pos.coords.longitude,accuracy:pos.coords.accuracy,
      donation_id:<?=!empty($assigned)?((int)$assigned[0]['id']):'0'?>,
      donation_type:<?=!empty($assigned)?"'".strtolower($assigned[0]['type'])."'":'\'food\''?>
    })}).catch(()=>{});
  },{enableHighAccuracy:true,maximumAge:30000});
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
