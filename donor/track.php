<?php
require_once __DIR__ . '/../config/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
$email = $_SESSION['user_email'];

$food=$conn->prepare(
  "SELECT *,COALESCE(donation_id,CONCAT('DON-FOOD-',LPAD(id,6,'0'))) AS don_id
   FROM food_donations WHERE donor_email=? ORDER BY created_at DESC");
$food->bind_param("s",$email);$food->execute();$food_res=$food->get_result();
$cloth=$conn->prepare(
  "SELECT *,COALESCE(donation_id,CONCAT('DON-CLO-',LPAD(id,6,'0'))) AS don_id
   FROM cloth_donations WHERE donor_email=? ORDER BY created_at DESC");
$cloth->bind_param("s",$email);$cloth->execute();$cloth_res=$cloth->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Track Donations | Adhaar</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#7a7d3f;--accent2:#9a8f5c;--bg:#f6f5f0;--card:#fff;--text:#2f2e26;--muted:#5a594d;--shadow:0 12px 36px rgba(47,46,38,.1);--radius:20px}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{background:var(--bg);color:var(--text);min-height:100vh}
.top-header{position:sticky;top:0;background:rgba(255,255,255,.96);backdrop-filter:blur(14px);z-index:100;box-shadow:0 2px 16px rgba(0,0,0,.07)}
.top-header-inner{max-width:900px;margin:auto;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:var(--accent);font-weight:700;font-size:14px;text-decoration:none;padding:7px 14px;border-radius:8px;border:1.5px solid rgba(122,125,63,.3);transition:.2s}
.back-btn:hover{background:rgba(122,125,63,.08)}
.live-badge{display:flex;align-items:center;gap:6px;background:#d1fae5;color:#065f46;padding:5px 13px;border-radius:20px;font-size:11px;font-weight:700}
.live-dot{width:8px;height:8px;border-radius:50%;background:#10b981;animation:livePulse 1.5s ease-in-out infinite}
@keyframes livePulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.4);opacity:.6}}
.page{padding:28px 20px 60px;max-width:900px;margin:0 auto}
.page-title{font-size:22px;font-weight:800;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:28px}
.section-head{font-size:16px;font-weight:800;margin:28px 0 16px;color:var(--accent);display:flex;align-items:center;gap:8px}
/* Track card */
.track-card{background:var(--card);padding:24px 28px;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;border-left:4px solid var(--accent);transition:.3s;animation:cardIn .35s ease forwards;opacity:0}
@keyframes cardIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.track-card:hover{transform:translateY(-3px);box-shadow:0 18px 50px rgba(47,46,38,.13)}
.tc-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.tc-id{font-size:15px;font-weight:800}
.badge{display:inline-block;padding:5px 13px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.pending,.submitted{background:#fef3c7;color:#92400e}
.accepted{background:#dbeafe;color:#1e40af}
.scheduled{background:#ede9fe;color:#5b21b6}
.out_for_pickup{background:#fce7f3;color:#9d174d}
.picked_up,.delivered{background:#d1fae5;color:#065f46}
.rejected{background:#fee2e2;color:#991b1b}
.tc-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:6px;margin-bottom:7px;font-size:13px}
.tc-row span{color:var(--muted)}
/* Progress bar */
.prog-wrap{margin:16px 0 8px}
.prog-track{height:6px;background:#e0ddd5;border-radius:6px;overflow:hidden}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:6px;transition:width 1s ease}
/* Timeline */
.tl{display:flex;align-items:flex-start;margin-top:16px;overflow-x:auto;padding-bottom:4px;-webkit-overflow-scrolling:touch}
.tl-step{display:flex;flex-direction:column;align-items:center;flex:1;min-width:60px;text-align:center;font-size:10px;color:var(--muted);gap:4px}
.tl-dot{width:14px;height:14px;border-radius:50%;background:#d4d0c4;flex-shrink:0;transition:.4s;position:relative;z-index:1}
.tl-step.done .tl-dot{background:var(--accent);box-shadow:0 0 0 4px rgba(122,125,63,.2)}
.tl-step.done{color:var(--accent);font-weight:700}
.tl-step.active-step .tl-dot{background:var(--accent2);box-shadow:0 0 0 4px rgba(154,143,92,.3);animation:dotPulse 1.8s ease infinite}
@keyframes dotPulse{0%,100%{box-shadow:0 0 0 4px rgba(154,143,92,.3)}50%{box-shadow:0 0 0 8px rgba(154,143,92,.1)}}
.tl-line{flex:1;height:2px;background:#d4d0c4;margin-bottom:19px;transition:.4s;min-width:12px}
.tl-line.done{background:var(--accent)}
.empty{text-align:center;padding:44px 20px;background:var(--card);border-radius:var(--radius);color:var(--muted);font-size:14px;box-shadow:var(--shadow)}
.empty .emoji{font-size:40px;display:block;margin-bottom:12px}
.auto-refresh{font-size:11px;color:var(--muted);text-align:center;margin-top:20px}
@media(max-width:600px){.tc-header{flex-direction:column;align-items:flex-start}.tc-row{flex-direction:column;gap:2px}}
</style>
</head>
<body>
<div class="top-header">
  <div class="top-header-inner">
    <a href="../index.html" style="display:inline-flex;align-items:center"><img src="../assets/logo.png" alt="SoulServe" style="height:28px;object-fit:contain"></a> <a href="donor_dashboard.php" class="back-btn">← Dashboard</a>
    <div class="live-badge"><span class="live-dot"></span>Live Tracking</div>
    <a href="donate.php" style="padding:8px 16px;border-radius:9px;background:var(--accent);color:#fff;font-size:13px;font-weight:700;text-decoration:none">+ Donate</a>
  </div>
</div>

<div class="page">
  <div class="page-title">📍 Track Your Donations</div>
  <div class="page-sub">Real-time status of all your food and clothing donations — updates instantly via live stream.</div>

<?php
$steps=['submitted','accepted','scheduled','out_for_pickup','picked_up'];
$step_labels=['Submitted','Accepted','Scheduled','Out for Pickup','Picked Up'];

function renderCard($row,$type,$steps,$step_labels){
  $current=array_search($row['status'],$steps);
  if($current===false) $current=0;
  $width=round((($current+1)/count($steps))*100);
  $status=$row['status'];
  $is_active=($status!=='delivered'&&$status!=='rejected');
  $delay = (int)($row['id'] % 5) * 80;
?>
<div class="track-card" style="animation-delay:<?=$delay?>ms;<?=$status==='rejected'?'border-left-color:#ef4444':''?>">
  <div class="tc-header">
    <div class="tc-id"><?=$type==='food'?'🍱':'👕'?> <span style="font-family:monospace;font-size:12px;background:rgba(122,125,63,.1);padding:2px 8px;border-radius:12px"><?=htmlspecialchars($row['don_id']??('#'.(int)$row['id']))?></span></div>
    <span class="badge <?=htmlspecialchars($status)?>"><?=ucfirst(str_replace('_',' ',$status))?></span>
  </div>
  <div class="tc-row"><span>Quantity:</span><strong><?=htmlspecialchars($row['quantity']??'—')?></strong></div>
  <div class="tc-row"><span>Address:</span><span style="text-align:right;max-width:280px"><?=htmlspecialchars($row['pickup_address']??'—')?></span></div>
  <?php if(!empty($row['pickup_date'])): ?>
  <div class="tc-row"><span>Pickup Date:</span><strong><?=htmlspecialchars($row['pickup_date'])?> <?=htmlspecialchars($row['pickup_time']??'')?></strong></div>
  <?php endif; ?>
  <?php if(!empty($row['volunteer_email'])): ?>
  <div class="tc-row"><span>Volunteer:</span><strong><?=htmlspecialchars($row['volunteer_email'])?></strong></div>
  <?php endif; ?>
  <div class="prog-wrap">
    <div class="prog-track"><div class="prog-fill" style="width:<?=$width?>%"></div></div>
  </div>
  <div class="tl">
    <?php foreach($steps as $i=>$s): $done=($i<=$current); $active_step=($i===$current&&$is_active); ?>
    <div class="tl-step <?=$done?'done':''?> <?=$active_step?'active-step':''?>">
      <div class="tl-dot"></div>
      <span><?=htmlspecialchars($step_labels[$i]??$s)?></span>
    </div>
    <?php if($i<count($steps)-1): ?><div class="tl-line <?=$i<$current?'done':''?>"></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php }?>

<div class="section-head">🍲 Food Donations</div>
<?php if($food_res->num_rows>0): ?>
  <div id="food-grid">
  <?php while($row=$food_res->fetch_assoc()): renderCard($row,'food',$steps,$step_labels); endwhile; ?>
  </div>
<?php else: ?>
  <div id="food-grid"></div>
  <div id="empty-food" class="empty"><span class="emoji">📭</span><p>No food donations yet. <a href="donate.php" style="color:var(--accent);font-weight:700">Donate now →</a></p></div>
<?php endif; ?>

<div class="section-head">👕 Clothing Donations</div>
<?php if($cloth_res->num_rows>0): ?>
  <div id="cloth-grid">
  <?php while($row=$cloth_res->fetch_assoc()): renderCard($row,'cloth',$steps,$step_labels); endwhile; ?>
  </div>
<?php else: ?>
  <div id="cloth-grid"></div>
  <div id="empty-cloth" class="empty"><span class="emoji">📭</span><p>No clothing donations yet. <a href="donate.php" style="color:var(--accent);font-weight:700">Donate now →</a></p></div>
<?php endif; ?>

<p class="auto-refresh" id="refreshMsg">🔴 <span id="sseStatus">Connecting to live stream…</span></p>
</div>
<script>
/* ── SSE real-time status updates ── */
(function(){
  const STEPS = ['submitted','accepted','scheduled','out_for_pickup','picked_up'];
  const STEP_LABELS = ['Submitted','Accepted','Scheduled','Out for Pickup','Picked Up'];

  function badgeClass(s){
    const map={pending:'pending',submitted:'submitted',accepted:'accepted',
               scheduled:'scheduled',out_for_pickup:'out_for_pickup',
               picked_up:'picked_up',delivered:'delivered',rejected:'rejected'};
    return map[s]||'pending';
  }
  function statusLabel(s){ return s.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()); }

  function buildCard(d){
    const idx   = STEPS.indexOf(d.status);
    const cur   = idx === -1 ? 0 : idx;
    const width = Math.round(((cur+1)/STEPS.length)*100);
    const icon  = d.type==='food'?'🍱':'👕';
    const isActive = (d.status!=='delivered'&&d.status!=='rejected');

    const tlHTML = STEPS.map((s,i)=>{
      const done   = i<=cur;
      const active = i===cur && isActive;
      const line   = i<STEPS.length-1
        ? `<div class="tl-line ${i<cur?'done':''}"></div>` : '';
      return `<div class="tl-step ${done?'done':''} ${active?'active-step':''}">
        <div class="tl-dot"></div><span>${STEP_LABELS[i]}</span></div>${line}`;
    }).join('');

    const pickupRow = d.pickup_date
      ? `<div class="tc-row"><span>Pickup:</span><strong>${d.pickup_date} ${d.pickup_time||''}</strong></div>` : '';
    const volRow = d.volunteer_email
      ? `<div class="tc-row"><span>Volunteer:</span><strong>${d.volunteer_email}</strong></div>` : '';

    return `<div class="track-card" data-don="${d.don_id}"
              style="${d.status==='rejected'?'border-left-color:#ef4444':''}">
      <div class="tc-header">
        <div class="tc-id">${icon} <span style="font-family:monospace;font-size:12px;
          background:rgba(122,125,63,.1);padding:2px 8px;border-radius:12px">${d.don_id}</span></div>
        <span class="badge ${badgeClass(d.status)}">${statusLabel(d.status)}</span>
      </div>
      <div class="tc-row"><span>Quantity:</span><strong>${d.quantity||'—'}</strong></div>
      <div class="tc-row"><span>Address:</span><span style="text-align:right;max-width:280px">${d.pickup_address||'—'}</span></div>
      ${pickupRow}${volRow}
      <div class="prog-wrap">
        <div class="prog-track"><div class="prog-fill" style="width:${width}%"></div></div>
      </div>
      <div class="tl">${tlHTML}</div>
    </div>`;
  }

  function renderAll(donations){
    const food  = donations.filter(d=>d.type==='food');
    const cloth = donations.filter(d=>d.type==='cloth');
    const emptyFood  = document.getElementById('empty-food');
    const emptyCloth = document.getElementById('empty-cloth');
    const foodGrid   = document.getElementById('food-grid');
    const clothGrid  = document.getElementById('cloth-grid');

    if(foodGrid){
      if(food.length){
        foodGrid.innerHTML  = food.map(buildCard).join('');
        if(emptyFood) emptyFood.style.display='none';
      } else {
        foodGrid.innerHTML='';
        if(emptyFood) emptyFood.style.display='';
      }
    }
    if(clothGrid){
      if(cloth.length){
        clothGrid.innerHTML = cloth.map(buildCard).join('');
        if(emptyCloth) emptyCloth.style.display='none';
      } else {
        clothGrid.innerHTML='';
        if(emptyCloth) emptyCloth.style.display='';
      }
    }
  }

  const statusEl = document.getElementById('sseStatus');
  function setStatus(msg, color){
    if(statusEl){ statusEl.textContent=msg; statusEl.style.color=color||''; }
  }

  function connect(){
    if(!window.EventSource){ setStatus('Live updates not supported — reload to refresh.','#ef4444'); return; }

    const es = new EventSource('../api/donation_status_stream.php');

    es.addEventListener('status', e=>{
      try{
        const data = JSON.parse(e.data);
        renderAll(data.donations||[]);
        setStatus('Live — last updated ' + new Date().toLocaleTimeString(), '#065f46');
      }catch(_){}
    });

    es.addEventListener('ping', ()=>{
      setStatus('Live — ' + new Date().toLocaleTimeString(), '#065f46');
    });

    es.addEventListener('close', ()=>{
      es.close();
      setTimeout(connect, 3000);   // server closed gracefully — reconnect
    });

    es.onerror = ()=>{
      setStatus('Reconnecting…', '#92400e');
      es.close();
      setTimeout(connect, 5000);
    };
  }

  connect();
})();
</script>
</body>
</html>
