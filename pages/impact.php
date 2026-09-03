<?php
header('Content-Type: text/html; charset=utf-8');
/**
 * pages/impact.php  — LIVE database-connected Impact page
 * Replaces the hardcoded impact.html
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/ai_engine.php';

$ai = adhaar_ai();

// ── Live counts from DB ───────────────────────────────────────
$food_del    = (int)$conn->query("SELECT COALESCE(SUM(quantity),0) c FROM food_donations WHERE status='delivered'")->fetch_assoc()['c'];
$cloth_del   = (int)$conn->query("SELECT COALESCE(SUM(quantity),0) c FROM cloth_donations WHERE status='delivered'")->fetch_assoc()['c'];
$food_total  = (int)$conn->query("SELECT COUNT(*) c FROM food_donations")->fetch_assoc()['c'];
$cloth_total = (int)$conn->query("SELECT COUNT(*) c FROM cloth_donations")->fetch_assoc()['c'];
$volunteers  = (int)$conn->query("SELECT COUNT(*) c FROM register WHERE role='volunteer' AND verified=1")->fetch_assoc()['c'];
$donors      = (int)$conn->query("SELECT COUNT(*) c FROM register WHERE role='donor' AND verified=1")->fetch_assoc()['c'];
$total_del   = (int)$conn->query("SELECT COUNT(*) c FROM food_donations WHERE status='delivered'")->fetch_assoc()['c']
              +(int)$conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE status='delivered'")->fetch_assoc()['c'];
$total_don   = $food_total + $cloth_total;
$del_rate    = $total_don > 0 ? round($total_del/$total_don*100) : 0;

// ── AI-predicted impact ───────────────────────────────────────
$impact   = $ai->predictImpact();
$forecast = $ai->demandForecast();

// Annual goals
$food_goal  = 200;
$cloth_goal = 100;
$vol_goal   = 50;
$areas_goal = 10;

$areas_q = $conn->query("SELECT COUNT(DISTINCT TRIM(SUBSTRING_INDEX(pickup_address,',',-1))) c FROM food_donations WHERE status='delivered'");
$areas   = max(1, (int)$areas_q->fetch_assoc()['c']);

// ── Weekly chart data ─────────────────────────────────────────
$weekly_labels = $weekly_food = $weekly_cloth = [];
for ($i = 7; $i >= 0; $i--) {
    $from = date('Y-m-d', strtotime("-".($i+1)." weeks"));
    $to   = date('Y-m-d', strtotime("-$i weeks"));
    $wf   = (int)$conn->query("SELECT COUNT(*) c FROM food_donations WHERE created_at BETWEEN '$from' AND '$to'")->fetch_assoc()['c'];
    $wc   = (int)$conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE created_at BETWEEN '$from' AND '$to'")->fetch_assoc()['c'];
    $weekly_labels[] = date('d M', strtotime("-$i weeks"));
    $weekly_food[]   = $wf;
    $weekly_cloth[]  = $wc;
}

// ── Status distribution ───────────────────────────────────────
$status_dist = $conn->query("SELECT status, COUNT(*) c FROM (SELECT status FROM food_donations UNION ALL SELECT status FROM cloth_donations) x GROUP BY status ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$status_labels = array_map(fn($r)=>ucfirst(str_replace('_',' ',$r['status'])), $status_dist);
$status_counts = array_column($status_dist,'c');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Live Impact | Adhaar – The SoulServe</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
:root{--accent:#006D77;--accent2:#2E8B57;--bg:#f5f8f4;--text:#102A43;--muted:#5A7184;--shadow:0 16px 48px rgba(16,42,67,.11);--radius:22px}
*{margin:0;padding:0;box-sizing:border-box}
.page-wrap{padding-top:82px}
section{padding:5.5rem 2rem}
.reveal{opacity:0;transform:translateY(28px);transition:.7s ease}.reveal.show{opacity:1;transform:none}
.sec-label{display:inline-block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--accent);background:rgba(122,125,63,.1);padding:5px 14px;border-radius:50px;margin-bottom:14px}
.sec-h{font-size:clamp(1.8rem,3.5vw,2.5rem);font-weight:900;color:var(--text);margin-bottom:1rem;line-height:1.15}
.sec-h span{color:var(--accent)}
/* Live badge */
.live-badge{display:inline-flex;align-items:center;gap:7px;background:#d1fae5;color:#065f46;padding:6px 16px;border-radius:20px;font-size:12px;font-weight:700;position:relative;z-index:1}
.live-dot{width:8px;height:8px;border-radius:50%;background:#10b981;animation:livePulse 1.5s ease-in-out infinite}
@keyframes livePulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.5);opacity:.6}}
/* Hero */
.imp-hero{background:linear-gradient(135deg,#102A43 0%,#006D77 55%,#2E8B57 100%);padding:6rem 2rem 5rem;text-align:center;position:relative;overflow:hidden}
.imp-hero::before{content:"";position:absolute;width:500px;height:500px;background:radial-gradient(circle,rgba(255,255,255,.15),transparent 65%);top:-150px;right:-100px;filter:blur(80px)}
.imp-hero h1{font-size:clamp(2.2rem,5vw,3.6rem);font-weight:900;color:#fff;line-height:1.1;margin-bottom:1rem;position:relative;z-index:1}
.imp-hero h1 span{background:linear-gradient(135deg,#7ad5cf,#d7f5d2);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.imp-hero p{font-size:1.05rem;color:rgba(255,255,255,.72);max-width:560px;margin:0 auto 1.5rem;line-height:1.8;position:relative;z-index:1}
/* AI impact cards */
.ai-impact-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1.4rem;margin-top:2rem}
.ai-card{background:#fff;border-radius:var(--radius);padding:2rem 1.6rem;text-align:center;box-shadow:var(--shadow);transition:.35s;border-bottom:4px solid var(--accent2);position:relative;overflow:hidden}
.ai-card:hover{transform:translateY(-7px);border-bottom-color:var(--accent)}
.ai-card::after{content:'🤖';position:absolute;right:10px;top:10px;font-size:1.1rem;opacity:.2}
.ai-num{font-size:2.6rem;font-weight:900;color:var(--accent);line-height:1;margin-bottom:.4rem}
.ai-label{font-size:.9rem;font-weight:700;color:var(--muted)}
.ai-sub{font-size:.75rem;color:var(--muted);margin-top:.3rem;opacity:.8}
/* Platform stats */
.platform-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1.4rem;margin-top:2rem}
.plat-card{background:#fff;border-radius:18px;padding:1.8rem 1.4rem;text-align:center;box-shadow:var(--shadow);transition:.3s;border-top:4px solid var(--accent2)}
.plat-card:hover{transform:translateY(-5px)}
.plat-num{font-size:2.2rem;font-weight:900;color:var(--accent);line-height:1;margin-bottom:.3rem}
.plat-label{font-size:.88rem;font-weight:600;color:var(--muted)}
/* Progress bars */
.prog-item{margin-bottom:1.8rem}
.prog-header{display:flex;justify-content:space-between;margin-bottom:.5rem;font-size:.9rem;font-weight:700;color:var(--text)}
.prog-track{height:12px;background:#e0ddd5;border-radius:10px;overflow:hidden}
.prog-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--accent),var(--accent2));width:0%;transition:width 1.4s cubic-bezier(.22,1,.36,1)}
/* Charts */
.charts-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:2.5rem;margin-top:3rem}
.chart-box{background:#fff;border-radius:var(--radius);padding:2rem;box-shadow:var(--shadow)}
.chart-box h4{font-size:.97rem;font-weight:800;margin-bottom:1.2rem;color:var(--text);display:flex;align-items:center;gap:7px}
.chart-wrap{position:relative;height:220px}
/* Forecast banner */
.forecast-banner{background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:16px;padding:20px 28px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:2.5rem}
.forecast-banner h4{font-size:16px;font-weight:800;margin-bottom:4px}
.forecast-banner p{font-size:13px;opacity:.9}
.forecast-val{font-size:32px;font-weight:900;opacity:.95}
/* SDG */
.sdg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1.8rem;margin-top:3rem}
.sdg-card{border-radius:18px;padding:2rem 1.6rem;transition:.35s;cursor:default;box-shadow:var(--shadow)}
.sdg-card:hover{transform:scale(1.04) translateY(-4px)}
.sdg-card.sdg1{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff}
.sdg-card.sdg2{background:linear-gradient(135deg,#d97706,#b45309);color:#fff}
.sdg-card.sdg5{background:linear-gradient(135deg,#db2777,#9d174d);color:#fff}
.sdg-card.sdg8{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff}
.sdg-card.sdg12{background:linear-gradient(135deg,#059669,#065f46);color:#fff}
.sdg-num{font-size:2.8rem;font-weight:900;opacity:.4;line-height:1;margin-bottom:.4rem}
.sdg-card h4{font-size:1rem;font-weight:800;margin-bottom:.5rem}
.sdg-card p{font-size:.85rem;opacity:.9;line-height:1.6}
/* CTA */
.imp-cta{background:linear-gradient(135deg,var(--accent),var(--accent2));padding:5rem 2rem;text-align:center}
.imp-cta h2{font-size:2.2rem;font-weight:900;color:#fff;margin-bottom:1rem}
.imp-cta p{color:rgba(255,255,255,.85);max-width:460px;margin:0 auto 2rem;line-height:1.8}
.w-btn{display:inline-block;padding:.9rem 2.4rem;border-radius:50px;background:#fff;color:var(--accent);font-weight:700;text-decoration:none;transition:.3s;margin:.4rem}
.w-btn:hover{transform:translateY(-3px);box-shadow:0 14px 32px rgba(0,0,0,.2)}
.o-btn{display:inline-block;padding:.9rem 2.4rem;border-radius:50px;border:2px solid rgba(255,255,255,.55);color:#fff;font-weight:700;text-decoration:none;transition:.3s;margin:.4rem}
.o-btn:hover{background:rgba(255,255,255,.12)}
@media(max-width:800px){.charts-grid{grid-template-columns:1fr}section{padding:4rem 1.4rem}}
</style>
</head>
<body>
<header class="header" id="header">
  <div class="nav-container">
    <a href="../index.html" class="logo-box">
      <div class="logo-mark"><img src="../assets/logo.png" alt="SoulServe" style="width:42px;height:42px;object-fit:contain;border-radius:6px;display:block"></div>
      <div class="logo-text">SoulServe<span>Endless Service. Infinite Impact.</span></div>
    </a>
    <nav class="nav" id="mobileMenu">
      <a href="../index.html" class="nav-link">Home</a>
      <a href="about.html" class="nav-link">About</a>
      <a href="activities.php" class="nav-link">Activities</a>
      <a href="impact.php" class="nav-link active">Impact</a>
      <a href="donate.html" class="nav-link">Donate</a>
      <a href="../shop/shop.php" class="nav-link">Shop</a>
      <a href="contact.html" class="nav-link">Contact</a>
    </nav>
    <div class="nav-actions">
      <a href="../auth/login.php" class="btn-nav">Login</a>
      <a href="../auth/register.php" class="btn-nav">Sign Up</a>
      <a href="donate.html" class="btn-nav-primary">Donate Now</a>
    </div>
    <div class="menu-icon" id="menuToggle">&#9776;</div>
  </div>
</header>

<div class="page-wrap">

<!-- HERO -->
<section class="imp-hero">
  <div class="live-badge" style="margin-bottom:1.4rem"><span class="live-dot"></span>Live Data · Updated <?=date('d M Y · h:i A')?></div>
  <h1>Our <span>Real Impact</span><br>In Numbers</h1>
  <p>Every figure below is pulled live from our database — no estimates, no marketing. Real donations, real people, real change.</p>
</section>

<!-- AI PREDICTED IMPACT -->
<section style="background:var(--bg);padding:5rem 2rem" class="reveal">
  <div style="max-width:1100px;margin:auto">
    <div style="text-align:center">
      <div class="sec-label">🤖 AI Impact Analysis</div>
      <h2 class="sec-h">Predicted Real-World <span>Impact</span></h2>
      <p style="color:var(--muted);max-width:540px;margin:0 auto;font-size:.97rem;line-height:1.8">Based on verified delivery data, our AI calculates the actual environmental and social impact of every donation.</p>
    </div>
    <div class="ai-impact-grid">
      <div class="ai-card"><div class="ai-num"><?=number_format($impact['people_fed'])?></div><div class="ai-label">People Fed</div><div class="ai-sub">Estimated from <?=number_format($food_del)?> food units</div></div>
      <div class="ai-card"><div class="ai-num"><?=number_format($impact['co2_saved_kg'])?><small style="font-size:1.2rem"> kg</small></div><div class="ai-label">CO₂ Saved</div><div class="ai-sub">Environmental impact</div></div>
      <div class="ai-card"><div class="ai-num"><?=number_format($impact['water_saved_ltr'])?><small style="font-size:1rem"> L</small></div><div class="ai-label">Water Saved</div><div class="ai-sub">Embedded water in food</div></div>
      <div class="ai-card"><div class="ai-num">₹<?=number_format($impact['economic_value'])?></div><div class="ai-label">Economic Value</div><div class="ai-sub">Estimated ₹ value created</div></div>
      <div class="ai-card"><div class="ai-num"><?=$del_rate?>%</div><div class="ai-label">Delivery Rate</div><div class="ai-sub"><?=$total_del?> of <?=$total_don?> donations delivered</div></div>
      <div class="ai-card"><div class="ai-num"><?=$areas?></div><div class="ai-label">Areas Covered</div><div class="ai-sub">Unique localities reached</div></div>
    </div>
  </div>
</section>

<!-- PLATFORM STATS -->
<section style="background:#fff;padding:5rem 2rem" class="reveal">
  <div style="max-width:1100px;margin:auto">
    <div style="text-align:center">
      <div class="sec-label">Live Platform Stats</div>
      <h2 class="sec-h">What We've <span>Achieved</span></h2>
    </div>
    <div class="platform-grid">
      <div class="plat-card"><div class="plat-num"><?=number_format($food_del)?></div><div class="plat-label">🍱 Meals Delivered</div></div>
      <div class="plat-card"><div class="plat-num"><?=number_format($cloth_del)?></div><div class="plat-label">👕 Clothing Kits</div></div>
      <div class="plat-card"><div class="plat-num"><?=$volunteers?></div><div class="plat-label">🤝 Active Volunteers</div></div>
      <div class="plat-card"><div class="plat-num"><?=$donors?></div><div class="plat-label">🎁 Registered Donors</div></div>
      <div class="plat-card"><div class="plat-num"><?=$food_total?></div><div class="plat-label">🍲 Food Donations</div></div>
      <div class="plat-card"><div class="plat-num"><?=$cloth_total?></div><div class="plat-label">👕 Cloth Donations</div></div>
    </div>
  </div>
</section>

<!-- AI DEMAND FORECAST BANNER -->
<section style="background:var(--bg);padding:4rem 2rem" class="reveal">
  <div style="max-width:900px;margin:auto">
    <div class="forecast-banner">
      <div>
        <h4>🤖 AI Demand Forecast</h4>
        <p>Based on the last 8 weeks of data, the AI predicts next week's donation volume.</p>
        <p style="margin-top:8px;opacity:.8;font-size:12px">Trend: <strong><?=ucfirst($forecast['trend'])?></strong> · Weekly avg: <?=$forecast['avg_per_week']?> donations</p>
      </div>
      <div style="text-align:center">
        <div class="forecast-val"><?=$forecast['predicted_next']?></div>
        <div style="font-size:13px;opacity:.85">donations predicted next week</div>
      </div>
    </div>
  </div>
</section>

<!-- CHARTS -->
<section style="background:#fff;padding:5rem 2rem" class="reveal">
  <div style="max-width:1000px;margin:auto">
    <div style="text-align:center"><div class="sec-label">Live Analytics</div><h2 class="sec-h">Donation <span>Trends</span></h2></div>
    <div class="charts-grid">
      <div class="chart-box">
        <h4>📈 Weekly Donations (Last 8 Weeks)</h4>
        <div class="chart-wrap"><canvas id="weeklyChart"></canvas></div>
      </div>
      <div class="chart-box">
        <h4>📊 Status Pipeline</h4>
        <div class="chart-wrap"><canvas id="statusChart"></canvas></div>
      </div>
    </div>
  </div>
</section>

<!-- PROGRESS TOWARDS GOALS -->
<section style="background:var(--bg);padding:5rem 2rem" class="reveal">
  <div style="max-width:800px;margin:auto">
    <div style="text-align:center;margin-bottom:2.5rem"><div class="sec-label">Goals Progress</div><h2 class="sec-h">Towards Annual <span>Targets</span></h2></div>
    <?php
header('Content-Type: text/html; charset=utf-8');
    $goals = [
      ['🍲 Food Donations',    $food_total,  $food_goal,  'Food units collected'],
      ['👕 Clothing Donations', $cloth_total, $cloth_goal, 'Clothing items collected'],
      ['🤝 Active Volunteers',  $volunteers,  $vol_goal,   'Volunteers onboarded'],
      ['🌍 Areas Covered',      $areas,       $areas_goal, 'Unique localities'],
    ];
    foreach($goals as [$label,$actual,$goal,$sub]): $pct=min(100,round($actual/$goal*100)); ?>
    <div class="prog-item">
      <div class="prog-header">
        <span><?=$label?> <small style="font-weight:500;color:var(--muted)">(<?=$sub?>)</small></span>
        <span><?=number_format($actual)?> / <?=number_format($goal)?> — <strong style="color:var(--accent)"><?=$pct?>%</strong></span>
      </div>
      <div class="prog-track"><div class="prog-fill" data-width="<?=$pct?>%"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- SDG ALIGNMENT -->
<section style="background:#fff;padding:5rem 2rem" class="reveal">
  <div style="max-width:1000px;margin:auto;text-align:center">
    <div class="sec-label">UN Sustainable Goals</div>
    <h2 class="sec-h">Real <span>SDG Progress</span></h2>
    <div class="sdg-grid">
      <div class="sdg-card sdg1"><div class="sdg-num">1</div><h4>No Poverty</h4><p>Seller module creates income for rural entrepreneurs.</p></div>
      <div class="sdg-card sdg2"><div class="sdg-num">2</div><h4>Zero Hunger</h4><p>Real-time food rescue — <?=number_format($food_del)?> meals delivered.</p></div>
      <div class="sdg-card sdg5"><div class="sdg-num">5</div><h4>Gender Equality</h4><p>Shop empowers women entrepreneurs to sell independently.</p></div>
      <div class="sdg-card sdg8"><div class="sdg-num">8</div><h4>Decent Work</h4><p><?=$volunteers?> volunteers gaining hands-on social service experience.</p></div>
      <div class="sdg-card sdg12"><div class="sdg-num">12</div><h4>Responsible Consumption</h4><p><?=$impact['co2_saved_kg']?> kg CO₂ saved through redistribution.</p></div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="imp-cta">
  <h2>Help Us Do More</h2>
  <p>Every donation, every purchase, every volunteer hour multiplies this impact.</p>
  <a href="donate.html" class="w-btn">Donate Now →</a>
  <a href="../auth/register.php" class="o-btn">Join as Volunteer / Seller</a>
</section>
</div>

<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div style="font-size:18px;font-weight:900;color:#fff;margin-bottom:12px">SoulServe</div>
        <p>Live impact data. Real donations. Measurable change. Technology powered by compassion.</p>
        <div class="footer-socials" style="margin-top:16px">
          <a href="mailto:adhaarsoulserve@gmail.com" class="social-btn">&#128231;</a>
          <a href="tel:+918237917354" class="social-btn">&#128222;</a>
        </div>
      </div>
      <div><h4>Platform</h4><div class="footer-links"><a href="donate.html">Donate</a><a href="../shop/shop.php">Marketplace</a><a href="impact.php">Impact</a><a href="activities.php">Activities</a></div></div>
      <div><h4>Company</h4><div class="footer-links"><a href="about.html">About</a><a href="contact.html">Contact</a><a href="../auth/register.php">Register</a><a href="../auth/login.php">Login</a></div></div>
      <div><h4>Contact</h4><div class="footer-links"><a href="mailto:adhaarsoulserve@gmail.com">adhaarsoulserve@gmail.com</a><a href="tel:+918237917354">+91 82379 17354</a><span>Kopargaon, Maharashtra</span></div></div>
    </div>
    <div class="footer-bottom">
      <p>&#169; 2026 SoulServe. All rights reserved.</p>
      <p style="background:var(--gradient-brand);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-weight:700">"Different Colors. One Humanity."</p>
    </div>
  </div>
  <div class="footer-rainbow"></div>
</footer>

<script src="../js/impact.js"></script>
<script>
const menuToggle=document.getElementById('menuToggle'),nav=document.getElementById('mobileMenu');
if(menuToggle&&nav)menuToggle.addEventListener('click',()=>nav.classList.toggle('show'));

// Progress bars
document.querySelectorAll('.prog-fill[data-width]').forEach(b=>{
  const io=new IntersectionObserver(entries=>{entries.forEach(e=>{if(e.isIntersecting){b.style.width=b.dataset.width;io.unobserve(b);}});},{threshold:.3});
  io.observe(b);
});

// Weekly chart
new Chart(document.getElementById('weeklyChart'),{
  type:'line',
  data:{
    labels:<?=json_encode($weekly_labels)?>,
    datasets:[
      {label:'Food',data:<?=json_encode($weekly_food)?>,borderColor:'#7a7d3f',backgroundColor:'rgba(122,125,63,.12)',tension:.4,fill:true,pointRadius:4},
      {label:'Clothing',data:<?=json_encode($weekly_cloth)?>,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.08)',tension:.4,fill:true,pointRadius:4}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:12,weight:'700'},padding:14}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:11}}},x:{grid:{display:false},ticks:{font:{size:10},maxRotation:45}}}}
});

// Status chart
new Chart(document.getElementById('statusChart'),{
  type:'doughnut',
  data:{
    labels:<?=json_encode($status_labels)?>,
    datasets:[{data:<?=json_encode($status_counts)?>,backgroundColor:['#fef3c7','#dbeafe','#ede9fe','#fce7f3','#d1fae5','#fee2e2'],borderWidth:3,borderColor:'#fff'}]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'60%',plugins:{legend:{position:'bottom',labels:{font:{size:11,weight:'700'},padding:12}}}}
});
</script>
<script src="../js/script.js"></script>
</body>
</html>
