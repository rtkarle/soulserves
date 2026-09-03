<?php
/**
 * Adhaar – Activities & News Page
 * News & Events section loads from DB (events_news table).
 * Activity cards (food/cloth/shop) remain as informational content.
 * Hero stats load live from DB.
 */
require_once __DIR__ . '/../config/db.php';

// ── Live stats from DB (safe) ────────────────────────────────
function safe_count($conn, string $sql): int {
    try { $r = $conn->query($sql); return $r ? (int)$r->fetch_assoc()['c'] : 0; }
    catch (Throwable $e) { return 0; }
}
$stat_food  = safe_count($conn, "SELECT COUNT(*) c FROM food_donations  WHERE status='delivered'");
$stat_cloth = safe_count($conn, "SELECT COUNT(*) c FROM cloth_donations WHERE status='delivered'");
$stat_vols  = safe_count($conn, "SELECT COUNT(*) c FROM register WHERE role='volunteer' AND verified=1");
$stat_areas = max(3, safe_count($conn, "SELECT COUNT(DISTINCT pickup_address) c FROM food_donations WHERE status='delivered'"));

// ── Published events & news from DB (safe — table may not exist yet) ──
$events_rows = [];
try {
    $chk = $conn->query("SHOW TABLES LIKE 'events_news'");
    if ($chk && $chk->num_rows > 0) {
        $eq = $conn->query(
            "SELECT id, title, content, category, emoji, image, event_date, created_at
             FROM events_news WHERE is_published = 1
             ORDER BY created_at DESC LIMIT 20"
        );
        if ($eq) $events_rows = $eq->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) { $events_rows = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activities &amp; News | Adhaar – The SoulServe</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
:root{--accent:#7a7d3f;--accent2:#9a8f5c;--bg:#f6f5f0;--text:#2f2e26;--muted:#5a594d;--shadow:0 16px 50px rgba(60,55,35,.11);--radius:22px}
*{margin:0;padding:0;box-sizing:border-box}
.page-wrap{padding-top:82px}
section{padding:5.5rem 2rem}
.reveal{opacity:0;transform:translateY(28px);transition:.7s ease}
.reveal.show{opacity:1;transform:none}
.sec-label{display:inline-block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--accent);background:rgba(122,125,63,.1);padding:5px 14px;border-radius:50px;margin-bottom:14px}
.sec-h{font-size:clamp(1.8rem,3.5vw,2.5rem);font-weight:900;color:var(--text);margin-bottom:1rem;line-height:1.15}
.sec-h span{color:var(--accent)}
.sec-p{color:var(--muted);font-size:1rem;line-height:1.8;max-width:620px}

/* ── Hero ── */
.act-hero{background:linear-gradient(135deg,#2f2e26,#4a4838,#2f2e26);padding:7rem 2rem 5rem;text-align:center;position:relative;overflow:hidden}
.act-hero::before,.act-hero::after{content:"";position:absolute;border-radius:50%;filter:blur(90px);opacity:.2;pointer-events:none}
.act-hero::before{width:500px;height:500px;background:#7a7d3f;top:-150px;left:-100px}
.act-hero::after{width:400px;height:400px;background:#9a8f5c;bottom:-80px;right:-80px}
.act-hero h1{font-size:clamp(2.2rem,5vw,3.6rem);font-weight:900;color:#fff;line-height:1.1;margin-bottom:1rem;position:relative;z-index:1}
.act-hero h1 span{background:linear-gradient(135deg,#9a8f5c,#c2b280);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.act-hero p{font-size:1.05rem;color:rgba(255,255,255,.72);max-width:580px;margin:0 auto 2.2rem;line-height:1.8;position:relative;z-index:1}
.hero-stats{display:flex;gap:1.5rem;justify-content:center;flex-wrap:wrap;position:relative;z-index:1}
.hs{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.15);border-radius:14px;padding:1rem 1.8rem;backdrop-filter:blur(8px);text-align:center;transition:.3s}
.hs:hover{background:rgba(255,255,255,.15);transform:translateY(-3px)}
.hs-val{font-size:1.9rem;font-weight:900;color:#fff}
.hs-lbl{font-size:11px;color:rgba(255,255,255,.6);font-weight:600;margin-top:2px}

/* ── Filter tabs ── */
.filter-bar{display:flex;gap:.6rem;flex-wrap:wrap;justify-content:center;margin-bottom:3rem}
.filter-btn{padding:9px 22px;border-radius:50px;border:2px solid #e0ddd5;background:#fff;font-size:13px;font-weight:700;color:var(--muted);cursor:pointer;transition:.25s;font-family:inherit}
.filter-btn:hover,.filter-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}

/* ── Activity cards ── */
.acts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:2rem;max-width:1100px;margin:0 auto}
.act-card{background:#fff;border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);transition:.35s}
.act-card:hover{transform:translateY(-7px);box-shadow:0 28px 65px rgba(60,55,35,.16)}
.act-card-img{width:100%;height:200px;object-fit:cover;background:linear-gradient(135deg,#f0ede5,#e4e0d4);display:flex;align-items:center;justify-content:center;font-size:4rem}
.act-card-body{padding:1.6rem}
.act-type{display:inline-block;padding:3px 12px;border-radius:50px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.8rem}
.type-food{background:#fef3c7;color:#92400e}
.type-cloth{background:#dbeafe;color:#1e40af}
.type-shop{background:#ede9fe;color:#5b21b6}
.act-card-body h3{font-size:1.07rem;font-weight:800;margin-bottom:.6rem;color:var(--text)}
.act-card-body p{font-size:.9rem;color:var(--muted);line-height:1.7;margin-bottom:1rem}
.act-meta{display:flex;gap:1rem;font-size:.8rem;color:var(--muted);font-weight:600;flex-wrap:wrap}

/* ── How donation works ── */
.how{background:var(--bg)}
.how-inner{max-width:1000px;margin:auto}
.stages{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1.4rem;margin-top:3rem;position:relative}
.stages::before{content:"";position:absolute;top:38px;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--accent),var(--accent2));z-index:0}
.stage{background:#fff;border-radius:16px;padding:2rem 1.4rem;text-align:center;box-shadow:var(--shadow);position:relative;z-index:1;transition:.3s}
.stage:hover{transform:translateY(-5px)}
.stage-num{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-weight:900;font-size:.95rem;display:flex;align-items:center;justify-content:center;margin:0 auto .9rem;box-shadow:0 6px 16px rgba(122,125,63,.4)}
.stage-icon{font-size:1.8rem;margin-bottom:.7rem}
.stage h4{font-size:.92rem;font-weight:800;color:var(--text);margin-bottom:.4rem}
.stage p{font-size:.82rem;color:var(--muted);line-height:1.6}

/* ── News section ── */
.news{background:#fff}
.news-inner{max-width:1000px;margin:auto}

/* DB news cards */
.news-list{display:grid;gap:1.5rem;margin-top:2.5rem}
.news-card{background:var(--bg);border-radius:16px;padding:1.8rem 2rem;display:flex;gap:1.6rem;align-items:flex-start;transition:.3s;border-left:5px solid var(--accent2)}
.news-card:hover{background:#fff;box-shadow:var(--shadow);transform:translateX(4px)}
.news-card-img{width:100px;height:76px;border-radius:10px;object-fit:cover;flex-shrink:0;border:1px solid #ede9df}
.news-emoji{font-size:2.4rem;flex-shrink:0;line-height:1}
.news-card h4{font-size:1rem;font-weight:800;color:var(--text);margin-bottom:.4rem}
.news-card p{font-size:.9rem;color:var(--muted);line-height:1.7}
.news-date{font-size:.78rem;color:var(--accent);font-weight:700;margin-top:.5rem;text-transform:uppercase;letter-spacing:.5px}
.news-cat-tag{display:inline-block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;padding:2px 9px;border-radius:20px;margin-left:8px;vertical-align:middle}
.cat-event{background:#dbeafe;color:#1e40af}
.cat-news{background:#d1fae5;color:#065f46}
.cat-drive{background:#fce7f3;color:#9d174d}
.cat-milestone{background:#ede9fe;color:#5b21b6}

/* No events state */
.no-events{text-align:center;padding:3rem 2rem;background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-top:2rem}
.no-events .ei{font-size:3rem;margin-bottom:1rem}
.no-events p{color:var(--muted);font-size:1rem}

/* ── CTA ── */
.act-cta{background:linear-gradient(135deg,var(--accent),var(--accent2));padding:5rem 2rem;text-align:center}
.act-cta h2{font-size:2.2rem;font-weight:900;color:#fff;margin-bottom:1rem}
.act-cta p{color:rgba(255,255,255,.85);font-size:1rem;margin-bottom:2rem;max-width:480px;margin-left:auto;margin-right:auto;line-height:1.7}
.cta-btn-white{padding:.9rem 2.4rem;border-radius:50px;background:#fff;color:var(--accent);font-weight:700;text-decoration:none;transition:.3s;font-size:.95rem;display:inline-block;margin:.4rem}
.cta-btn-white:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,.2)}
.cta-btn-ol{padding:.9rem 2.4rem;border-radius:50px;border:2px solid rgba(255,255,255,.55);color:#fff;font-weight:700;text-decoration:none;transition:.3s;font-size:.95rem;display:inline-block;margin:.4rem}
.cta-btn-ol:hover{background:rgba(255,255,255,.15)}

@media(max-width:700px){
  .stages::before{display:none}
  section{padding:4rem 1.4rem}
  .news-card{flex-direction:column;gap:.8rem}
  .news-card-img{width:100%;height:160px}
}
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
      <a href="activities.php" class="nav-link active">Activities</a>
      <a href="impact.php" class="nav-link">Impact</a>
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

<!-- ══ HERO ══ -->
<section class="act-hero">
  <h1>Our <span>Activities</span> &amp;<br>Donation Work</h1>
  <p>Every pickup, every delivery, every sale — tracked and transparent. See what Adhaar does every day to fight hunger, reduce waste, and empower communities.</p>
  <div class="hero-stats">
    <div class="hs">
      <div class="hs-val"><?=$stat_food?>+</div>
      <div class="hs-lbl">Meals Delivered</div>
    </div>
    <div class="hs">
      <div class="hs-val"><?=$stat_cloth?>+</div>
      <div class="hs-lbl">Clothing Kits</div>
    </div>
    <div class="hs">
      <div class="hs-val"><?=$stat_vols?>+</div>
      <div class="hs-lbl">Volunteers Active</div>
    </div>
    <div class="hs">
      <div class="hs-val"><?=$stat_areas?>+</div>
      <div class="hs-lbl">Areas Covered</div>
    </div>
  </div>
</section>

<!-- ══ ACTIVITY CARDS ══ -->
<section style="background:#fff;padding:5rem 2rem" class="reveal">
  <div style="max-width:1100px;margin:0 auto">
    <div style="text-align:center;margin-bottom:2.5rem">
      <div class="sec-label">What We Do</div>
      <h2 class="sec-h">Donation <span>Activities</span></h2>
    </div>
    <div class="filter-bar">
      <button class="filter-btn active" onclick="filterCards('all',this)">All Activities</button>
      <button class="filter-btn" onclick="filterCards('food',this)">🍲 Food</button>
      <button class="filter-btn" onclick="filterCards('cloth',this)">👕 Clothing</button>
      <button class="filter-btn" onclick="filterCards('shop',this)">🛍️ Shop &amp; Empower</button>
    </div>
    <div class="acts-grid">

      <div class="act-card" data-cat="food">
        <div class="act-card-img">🍱</div>
        <div class="act-card-body">
          <span class="act-type type-food">Food Rescue</span>
          <h3>Surplus Cooked Food Collection</h3>
          <p>Donors submit freshly cooked surplus food through our platform. Admin verifies, a volunteer is assigned, and pickup is completed — often within 2–4 hours.</p>
          <div class="act-meta"><span>⏱ Avg. 3 hrs</span><span>🌡 Same-day delivery</span></div>
        </div>
      </div>

      <div class="act-card" data-cat="food">
        <div class="act-card-img">🎉</div>
        <div class="act-card-body">
          <span class="act-type type-food">Event Rescue</span>
          <h3>Post-Event Food Redistribution</h3>
          <p>Weddings, corporate events, and community gatherings regularly produce food surplus. Adhaar provides a fast, dignified route to redirect this to beneficiaries before it spoils.</p>
          <div class="act-meta"><span>🏢 Events &amp; Weddings</span><span>✅ Verified pickup</span></div>
        </div>
      </div>

      <div class="act-card" data-cat="cloth">
        <div class="act-card-img">👕</div>
        <div class="act-card-body">
          <span class="act-type type-cloth">Clothing Drive</span>
          <h3>Seasonal Clothing Collection</h3>
          <p>Families donate clean, wearable clothing through our simple form. Clothes are sorted by type, condition, and size, then delivered to communities that need them most.</p>
          <div class="act-meta"><span>📦 All types accepted</span><span>🧺 Clean &amp; sorted</span></div>
        </div>
      </div>

      <div class="act-card" data-cat="cloth">
        <div class="act-card-img">❄️</div>
        <div class="act-card-body">
          <span class="act-type type-cloth">Winter Drive</span>
          <h3>Warm Clothes Before Winter</h3>
          <p>Targeted campaigns before the cold season collect jackets, sweaters, and blankets for rural and urban-poor communities. Timed, tracked, and transparent.</p>
          <div class="act-meta"><span>📅 Seasonal</span><span>🎯 Targeted delivery</span></div>
        </div>
      </div>

      <div class="act-card" data-cat="shop">
        <div class="act-card-img">🎨</div>
        <div class="act-card-body">
          <span class="act-type type-shop">Empowerment Shop</span>
          <h3>Artisan &amp; Seller Platform</h3>
          <p>Rural craftspeople, women's self-help groups, and local entrepreneurs list and sell products directly. Every purchase is a livelihood investment, not just a transaction.</p>
          <div class="act-meta"><span>🏪 Direct-to-buyer</span><span>💰 No middlemen</span></div>
        </div>
      </div>

      <div class="act-card" data-cat="shop">
        <div class="act-card-img">🌿</div>
        <div class="act-card-body">
          <span class="act-type type-shop">Rural Products</span>
          <h3>Organic &amp; Handmade Goods</h3>
          <p>From hand-woven textiles to organic spices and traditional jewelry — products made by skilled, underserved communities reach buyers across India through Adhaar Shop.</p>
          <div class="act-meta"><span>🚚 Meesho-style delivery</span><span>⭐ Verified sellers</span></div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ══ HOW DONATION WORKS ══ -->
<section class="how reveal">
  <div class="how-inner">
    <div style="text-align:center">
      <div class="sec-label">The Process</div>
      <h2 class="sec-h">How a Donation <span>Moves</span></h2>
      <p class="sec-p" style="margin:0 auto">From your doorstep to a family in need — every step verified, every status tracked in real time.</p>
    </div>
    <div class="stages">
      <div class="stage"><div class="stage-num">1</div><div class="stage-icon">📝</div><h4>Submit</h4><p>Donor fills a simple form with photo, quantity, and pickup details.</p></div>
      <div class="stage"><div class="stage-num">2</div><div class="stage-icon">🛡️</div><h4>Verify</h4><p>Admin reviews and approves the donation for quality and safety.</p></div>
      <div class="stage"><div class="stage-num">3</div><div class="stage-icon">📅</div><h4>Schedule</h4><p>A volunteer is assigned. Pickup date and time are confirmed via email.</p></div>
      <div class="stage"><div class="stage-num">4</div><div class="stage-icon">🚚</div><h4>Pickup</h4><p>Volunteer collects donation from the address provided by the donor.</p></div>
      <div class="stage"><div class="stage-num">5</div><div class="stage-icon">📸</div><h4>Proof</h4><p>Volunteer uploads a delivery photo. Donor receives an email with the proof image.</p></div>
      <div class="stage"><div class="stage-num">6</div><div class="stage-icon">🤝</div><h4>Done</h4><p>Donation delivered with dignity. Full record kept for transparency.</p></div>
    </div>
  </div>
</section>

<!-- ══ EVENTS & NEWS (DB-backed) ══ -->
<section class="news reveal">
  <div class="news-inner">
    <div style="text-align:center;margin-bottom:.5rem">
      <div class="sec-label">Updates</div>
      <h2 class="sec-h">Latest <span>News &amp; Events</span></h2>
      <p class="sec-p" style="margin:.5rem auto 0">Real updates registered by the Adhaar team — no hardcoded content.</p>
    </div>

    <?php if(empty($events_rows)): ?>
    <div class="no-events">
      <div class="ei">📰</div>
      <p>No news or events published yet. Check back soon!</p>
    </div>
    <?php else: ?>
    <div class="news-list">
      <?php foreach($events_rows as $ev):
        $cat   = htmlspecialchars($ev['category'] ?? 'news');
        $emoji = htmlspecialchars($ev['emoji']    ?? '📰');
        $date_str = '';
        if (!empty($ev['event_date']) && $ev['event_date'] !== '0000-00-00') {
            $date_str = date('F Y', strtotime($ev['event_date']));
        } else {
            $date_str = date('F Y', strtotime($ev['created_at']));
        }
      ?>
      <div class="news-card">
        <?php if(!empty($ev['image'])): ?>
          <img src="../<?=htmlspecialchars($ev['image'])?>" alt="<?=htmlspecialchars($ev['title'])?>" class="news-card-img">
        <?php else: ?>
          <div class="news-emoji"><?=$emoji?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <h4>
            <?=htmlspecialchars($ev['title'])?>
            <span class="news-cat-tag cat-<?=$cat?>"><?=ucfirst($cat)?></span>
          </h4>
          <p><?=htmlspecialchars(mb_substr($ev['content'], 0, 200)).(mb_strlen($ev['content'])>200?'…':'')?></p>
          <div class="news-date">📅 <?=$date_str?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ CTA ══ -->
<section class="act-cta">
  <h2>Ready to Contribute?</h2>
  <p>Donate food or clothes, volunteer for pickups, or sell your products on Adhaar Shop.</p>
  <a href="donate.html" class="cta-btn-white">Donate Now →</a>
  <a href="../auth/register.php" class="cta-btn-ol">Join as Volunteer / Seller</a>
</section>

</div><!-- .page-wrap -->

<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div style="font-size:18px;font-weight:900;color:#fff;margin-bottom:12px">SoulServe</div>
        <p>Connecting surplus food and clothing to communities in need, reducing waste while restoring dignity.</p>
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

<script>
// Mobile menu toggle
const _h2=document.getElementById('header');if(_h2)window.addEventListener('scroll',()=>_h2.classList.toggle('scrolled',scrollY>60),{passive:true});const _mt2=document.getElementById('menuToggle'),_mn2=document.getElementById('mobileMenu');if(_mt2&&_mn2){_mt2.addEventListener('click',()=>{_mn2.classList.toggle('show');_mt2.textContent=_mn2.classList.contains('show')?'✕':'☰';});}// Scroll reveal
const revels = document.querySelectorAll('.reveal');
const ro = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) { e.target.classList.add('show'); ro.unobserve(e.target); }
  });
}, { threshold: .1 });
revels.forEach(el => ro.observe(el));

// Activity card filter
function filterCards(cat, btn) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.act-card').forEach(c => {
    c.style.display = (cat === 'all' || c.dataset.cat === cat) ? '' : 'none';
  });
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
