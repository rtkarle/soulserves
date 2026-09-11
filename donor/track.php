<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
$email = $_SESSION['user_email'];

/* ── Ensure donations table + donation_id columns exist ── */
try {
    // New unified donations table
    $conn->query("CREATE TABLE IF NOT EXISTS donations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        donation_id VARCHAR(30) UNIQUE,
        donor_email VARCHAR(180) NOT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'other',
        quantity VARCHAR(100),
        description TEXT,
        condition_type VARCHAR(20) DEFAULT 'good',
        pickup_address TEXT,
        contact VARCHAR(20),
        pickup_date DATE, pickup_time TIME,
        image VARCHAR(400),
        status ENUM('pending','accepted','rejected','scheduled','out_for_pickup','picked_up','delivered') NOT NULL DEFAULT 'pending',
        volunteer_email VARCHAR(180),
        notes TEXT,
        priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
        food_time DATETIME, safe_hours INT,
        cloth_type VARCHAR(80), is_clean TINYINT(1) DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_donor(donor_email), INDEX idx_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add donation_id to old food_donations if missing
    $fc = $conn->query("SHOW COLUMNS FROM food_donations LIKE 'donation_id'");
    if ($fc && $fc->num_rows === 0) {
        $conn->query("ALTER TABLE food_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id");
        $conn->query("UPDATE food_donations SET donation_id=CONCAT('DON-FOOD-',LPAD(id,6,'0')) WHERE donation_id IS NULL");
    }
    // Add donation_id to old cloth_donations if missing
    $cc = $conn->query("SHOW COLUMNS FROM cloth_donations LIKE 'donation_id'");
    if ($cc && $cc->num_rows === 0) {
        $conn->query("ALTER TABLE cloth_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id");
        $conn->query("UPDATE cloth_donations SET donation_id=CONCAT('DON-CLO-',LPAD(id,6,'0')) WHERE donation_id IS NULL");
    }
} catch (Throwable $e) {
    error_log("[track] Table setup: " . $e->getMessage());
}

/* ── Fetch from new unified donations table ── */
$new_donations = [];
try {
    $sq = $conn->prepare(
        "SELECT id, donation_id, category, quantity, description,
                pickup_address, pickup_date, pickup_time,
                volunteer_email, status, priority, created_at, image
         FROM donations WHERE donor_email=? ORDER BY created_at DESC"
    );
    $sq->bind_param("s", $email); $sq->execute();
    $new_donations = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) { $new_donations = []; }

/* ── Fetch from legacy food_donations ── */
$food_rows = [];
try {
    $fq = $conn->prepare(
        "SELECT id, COALESCE(donation_id,CONCAT('DON-FOOD-',LPAD(id,6,'0'))) AS donation_id,
                'food' AS category, quantity, NULL AS description,
                pickup_address, pickup_date, pickup_time,
                volunteer_email, status, priority, created_at, image
         FROM food_donations WHERE donor_email=? ORDER BY created_at DESC"
    );
    $fq->bind_param("s", $email); $fq->execute();
    $food_rows = $fq->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) { $food_rows = []; }

/* ── Fetch from legacy cloth_donations ── */
$cloth_rows = [];
try {
    $cq = $conn->prepare(
        "SELECT id, COALESCE(donation_id,CONCAT('DON-CLO-',LPAD(id,6,'0'))) AS donation_id,
                'clothes' AS category, quantity, NULL AS description,
                pickup_address, pickup_date, pickup_time,
                volunteer_email, status, 'medium' AS priority, created_at, image
         FROM cloth_donations WHERE donor_email=? ORDER BY created_at DESC"
    );
    $cq->bind_param("s", $email); $cq->execute();
    $cloth_rows = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) { $cloth_rows = []; }

/* ── Merge all donations ── */
$all_donations = array_merge($new_donations, $food_rows, $cloth_rows);
usort($all_donations, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));

/* ── Category config ── */
$cat_config = [
    'food'            => ['icon'=>'🍱', 'label'=>'Food',           'color'=>'#92400e', 'bg'=>'#fef3c7'],
    'clothes'         => ['icon'=>'👕', 'label'=>'Clothes',         'color'=>'#1e40af', 'bg'=>'#dbeafe'],
    'study_material'  => ['icon'=>'📚', 'label'=>'Study Material',  'color'=>'#065f46', 'bg'=>'#d1fae5'],
    'school_supplies' => ['icon'=>'🎒', 'label'=>'School Supplies', 'color'=>'#5b21b6', 'bg'=>'#ede9fe'],
    'toys'            => ['icon'=>'🧸', 'label'=>'Toys',            'color'=>'#9d174d', 'bg'=>'#fce7f3'],
    'medicines'       => ['icon'=>'💊', 'label'=>'Medicines',       'color'=>'#991b1b', 'bg'=>'#fee2e2'],
    'electronics'     => ['icon'=>'📱', 'label'=>'Electronics',     'color'=>'#1e3a5f', 'bg'=>'#bfdbfe'],
    'furniture'       => ['icon'=>'🪑', 'label'=>'Furniture',       'color'=>'#78350f', 'bg'=>'#fef3c7'],
    'other'           => ['icon'=>'📦', 'label'=>'Other',           'color'=>'#374151', 'bg'=>'#f3f4f6'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Track Donations | SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#006D77;--accent2:#2E8B57;--bg:#f5f8f4;--card:#fff;--text:#102A43;--muted:#5A7184;--shadow:0 12px 36px rgba(16,42,67,.09);--radius:20px;--border:#E2EBE9}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{background:var(--bg);color:var(--text);min-height:100vh}
/* Header */
.top-header{position:sticky;top:0;background:rgba(255,255,255,.96);backdrop-filter:blur(14px);z-index:100;box-shadow:0 2px 16px rgba(0,0,0,.07)}
.top-header-inner{max-width:960px;margin:auto;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:var(--accent);font-weight:700;font-size:13px;text-decoration:none;padding:7px 14px;border-radius:8px;border:1.5px solid rgba(0,109,119,.3);transition:.2s}
.back-btn:hover{background:rgba(0,109,119,.07)}
.live-badge{display:flex;align-items:center;gap:6px;background:#d1fae5;color:#065f46;padding:5px 13px;border-radius:20px;font-size:11px;font-weight:700}
.live-dot{width:8px;height:8px;border-radius:50%;background:#10b981;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.4);opacity:.6}}
/* Page */
.page{padding:28px 20px 80px;max-width:960px;margin:0 auto}
.page-title{font-size:22px;font-weight:800;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:24px}
/* Filter tabs */
.filter-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px}
.filter-tab{padding:7px 16px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:.2s;color:var(--muted)}
.filter-tab:hover,.filter-tab.active{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-color:transparent}
/* Track card */
.track-card{background:var(--card);padding:22px 26px;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:16px;border-left:4px solid var(--accent);transition:.3s;animation:cardIn .35s ease forwards;opacity:0}
@keyframes cardIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.track-card:hover{transform:translateY(-3px);box-shadow:0 18px 50px rgba(16,42,67,.12)}
.tc-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.tc-left{display:flex;flex-direction:column;gap:4px}
.tc-id{font-size:12px;font-family:monospace;background:rgba(0,109,119,.08);padding:3px 10px;border-radius:10px;color:var(--accent);font-weight:700;display:inline-block}
.tc-cat{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:4px 12px;border-radius:12px}
.badge{display:inline-block;padding:5px 13px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.pending,.submitted{background:#fef3c7;color:#92400e}
.accepted{background:#dbeafe;color:#1e40af}
.scheduled{background:#ede9fe;color:#5b21b6}
.out_for_pickup{background:#fce7f3;color:#9d174d}
.picked_up,.delivered{background:#d1fae5;color:#065f46}
.rejected{background:#fee2e2;color:#991b1b}
.tc-row{display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:6px;font-size:13px;flex-wrap:wrap}
.tc-row span:first-child{color:var(--muted);flex-shrink:0}
/* Progress */
.prog-wrap{margin:14px 0 6px}
.prog-track{height:5px;background:#E2EBE9;border-radius:6px;overflow:hidden}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:6px;transition:width 1s ease}
/* Timeline */
.tl{display:flex;align-items:flex-start;margin-top:14px;overflow-x:auto;padding-bottom:4px}
.tl-step{display:flex;flex-direction:column;align-items:center;flex:1;min-width:52px;text-align:center;font-size:10px;color:var(--muted);gap:3px}
.tl-dot{width:12px;height:12px;border-radius:50%;background:#d4d0c4;transition:.4s}
.tl-step.done .tl-dot{background:var(--accent);box-shadow:0 0 0 3px rgba(0,109,119,.2)}
.tl-step.done{color:var(--accent);font-weight:700}
.tl-step.active-step .tl-dot{background:var(--accent2);animation:dotPulse 1.8s infinite}
@keyframes dotPulse{0%,100%{box-shadow:0 0 0 3px rgba(46,139,87,.3)}50%{box-shadow:0 0 0 7px rgba(46,139,87,.1)}}
.tl-line{flex:1;height:2px;background:#d4d0c4;margin-bottom:17px;min-width:10px;transition:.4s}
.tl-line.done{background:var(--accent)}
/* Empty */
.empty{text-align:center;padding:48px 20px;background:var(--card);border-radius:var(--radius);color:var(--muted);font-size:14px;box-shadow:var(--shadow)}
.empty-icon{font-size:42px;display:block;margin-bottom:14px}
/* SSE status */
.sse-bar{font-size:11px;color:var(--muted);text-align:center;margin-top:20px;display:flex;align-items:center;justify-content:center;gap:6px}
@media(max-width:600px){.tc-header{flex-direction:column}.tc-row{flex-direction:column;gap:2px}}
</style>
</head>
<body>
<div class="top-header">
  <div class="top-header-inner">
    <a href="../assets/logo.png" style="display:none"></a>
    <a href="donor_dashboard.php" class="back-btn">← Dashboard</a>
    <div style="font-size:15px;font-weight:800;color:var(--text)">📍 Track Donations</div>
    <div class="live-badge"><span class="live-dot"></span>Live</div>
  </div>
</div>

<div class="page">
  <div class="page-title">Your Donation History</div>
  <div class="page-sub">All donations — real-time status updates every 20 seconds.</div>

  <!-- Search & Quick Status Filters -->
  <div class="track-search-wrap">
    <span class="track-search-icon">🔍</span>
    <input type="text" id="trackSearch" class="track-search-input" placeholder="Search by Donation ID, item description, or address..." oninput="applyFilters()">
  </div>

  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:14px">
    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-right:4px">Status:</span>
    <button class="filter-tab active" data-filter-type="status" data-val="all" onclick="filterStatus('all',this)">All Status</button>
    <button class="filter-tab" data-filter-type="status" data-val="active" onclick="filterStatus('active',this)">⚡ Active Pickups</button>
    <button class="filter-tab" data-filter-type="status" data-val="delivered" onclick="filterStatus('delivered',this)">✅ Delivered</button>
  </div>

  <!-- Filter tabs -->
  <div class="filter-tabs">
    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;margin-right:4px">Category:</span>
    <button class="filter-tab active" data-filter-type="cat" data-val="all" onclick="filterDonations('all',this)">All (<?=count($all_donations)?>)</button>
    <?php
    $cat_counts = array_count_values(array_column($all_donations, 'category'));
    foreach ($cat_config as $key => $cfg):
        if (!isset($cat_counts[$key]) || $cat_counts[$key] == 0) continue;
    ?>
    <button class="filter-tab" data-filter-type="cat" data-val="<?=$key?>" onclick="filterDonations('<?=$key?>',this)"><?=$cfg['icon']?> <?=$cfg['label']?> (<?=$cat_counts[$key]?>)</button>
    <?php endforeach; ?>
  </div>

<?php if(empty($all_donations)): ?>
  <div class="empty">
    <span class="empty-icon">📭</span>
    <p>No donations yet.</p>
    <a href="donate.php" style="display:inline-block;margin-top:14px;padding:10px 24px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-radius:50px;font-weight:700;font-size:13px;text-decoration:none">+ Donate Now</a>
  </div>
<?php else: ?>

<?php
$steps       = ['pending','accepted','scheduled','out_for_pickup','picked_up','delivered'];
$step_labels = ['Pending','Accepted','Scheduled','Out for Pickup','Picked Up','Delivered'];

foreach ($all_donations as $idx => $row):
    $cat    = $row['category'] ?? 'other';
    $cfg    = $cat_config[$cat] ?? $cat_config['other'];
    $status = $row['status'] ?? 'pending';
    $cur    = array_search($status, $steps);
    if ($cur === false) $cur = 0;
    $width  = round((($cur + 1) / count($steps)) * 100);
    $is_active = !in_array($status, ['delivered','rejected']);
    $delay  = ($idx % 6) * 80;
    $don_id = $row['donation_id'] ?? ('DON-' . strtoupper($cat) . '-' . str_pad($row['id'],6,'0',STR_PAD_LEFT));
?>
<div class="track-card" data-cat="<?=htmlspecialchars($cat)?>" data-status="<?=htmlspecialchars($status)?>" data-active="<?=$is_active?'1':'0'?>" style="animation-delay:<?=$delay?>ms;<?=$status==='rejected'?'border-left-color:#ef4444':($status==='delivered'?'border-left-color:#10b981':'')?>">
  <div class="tc-header">
    <div class="tc-left">
      <span class="tc-cat" style="background:<?=$cfg['bg']?>;color:<?=$cfg['color']?>"><?=$cfg['icon']?> <?=$cfg['label']?></span>
      <span class="tc-id"><?=htmlspecialchars($don_id)?></span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <a href="../api/donation_receipt.php?id=<?=urlencode($don_id)?>&type=<?=urlencode($cat)?>" target="_blank" class="btn-receipt-modern" style="padding:4px 12px;font-size:11px" title="Print Official Receipt">🖨️ Receipt</a>
      <span class="badge <?=htmlspecialchars($status)?>"><?=ucfirst(str_replace('_',' ',$status))?></span>
    </div>
  </div>
  <div class="tc-row"><span>Quantity:</span><strong><?=htmlspecialchars($row['quantity'] ?? '—')?></strong></div>
  <?php if(!empty($row['description'])): ?>
  <div class="tc-row"><span>Details:</span><span style="max-width:320px;text-align:right"><?=htmlspecialchars(mb_substr($row['description'],0,80))?></span></div>
  <?php endif; ?>
  <div class="tc-row"><span>Address:</span><span style="text-align:right;max-width:300px"><?=htmlspecialchars($row['pickup_address'] ?? '—')?></span></div>
  <?php if(!empty($row['pickup_date'])): ?>
  <div class="tc-row"><span>Pickup:</span><strong><?=htmlspecialchars($row['pickup_date'])?> <?=htmlspecialchars($row['pickup_time'] ?? '')?></strong></div>
  <?php endif; ?>
  <?php if(!empty($row['volunteer_email'])): ?>
  <div class="tc-row">
    <span>Volunteer:</span>
    <span style="display:inline-flex;align-items:center;gap:8px">
      <strong><?=htmlspecialchars($row['volunteer_email'])?></strong>
      <a href="mailto:<?=htmlspecialchars($row['volunteer_email'])?>" class="vol-contact-chip" style="padding:2px 8px;font-size:10px">✉️ Email</a>
    </span>
  </div>
  <?php endif; ?>
  <?php
  $since = $row['created_at'] ? human_time_diff(strtotime($row['created_at'])) : '';
  function human_time_diff($ts) {
      $diff = time() - $ts;
      if ($diff < 60) return 'just now';
      if ($diff < 3600) return floor($diff/60) . 'm ago';
      if ($diff < 86400) return floor($diff/3600) . 'h ago';
      return floor($diff/86400) . 'd ago';
  }
  ?>
  <div class="tc-row"><span>Submitted:</span><span><?=htmlspecialchars($since)?></span></div>
  <div class="prog-wrap">
    <div class="prog-track"><div class="prog-fill" style="width:<?=$width?>%"></div></div>
  </div>
  <div class="tl">
    <?php foreach($steps as $i => $s):
      $done   = ($i <= $cur);
      $active = ($i === $cur && $is_active);
    ?>
    <div class="tl-step <?=$done?'done':''?> <?=$active?'active-step':''?>">
      <div class="tl-dot"></div>
      <span><?=htmlspecialchars($step_labels[$i])?></span>
    </div>
    <?php if($i < count($steps)-1): ?>
    <div class="tl-line <?=$i < $cur?'done':''?>"></div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

  <div class="sse-bar">🔴 <span id="sseStatus">Connecting to live stream…</span></div>
  <div style="text-align:center;margin-top:24px">
    <a href="donate.php" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-radius:50px;font-weight:700;font-size:13px;text-decoration:none;box-shadow:0 8px 24px rgba(0,109,119,.2)">+ New Donation</a>
  </div>
</div>

<script>
// Combined Category + Status + Keyword Filtering
let currentCat = 'all';
let currentStatus = 'all';

function filterDonations(cat, btn) {
  document.querySelectorAll('.filter-tab[data-filter-type="cat"]').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentCat = cat;
  applyFilters();
}

function filterStatus(st, btn) {
  document.querySelectorAll('.filter-tab[data-filter-type="status"]').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentStatus = st;
  applyFilters();
}

function applyFilters() {
  const query = (document.getElementById('trackSearch')?.value || '').toLowerCase().trim();
  document.querySelectorAll('.track-card').forEach(card => {
    const cardCat = card.dataset.cat || '';
    const cardStatus = card.dataset.status || '';
    const isActive = card.dataset.active === '1';
    const cardText = card.innerText.toLowerCase();

    const matchesCat = (currentCat === 'all' || cardCat === currentCat);
    let matchesStatus = true;
    if (currentStatus === 'active') matchesStatus = isActive;
    else if (currentStatus === 'delivered') matchesStatus = (cardStatus === 'delivered');
    else if (currentStatus !== 'all') matchesStatus = (cardStatus === currentStatus);

    const matchesSearch = (!query || cardText.includes(query));

    card.style.display = (matchesCat && matchesStatus && matchesSearch) ? '' : 'none';
  });
}

// SSE live updates
const statusEl = document.getElementById('sseStatus');
function setStatus(msg, color) {
  if (statusEl) { statusEl.textContent = msg; if (color) statusEl.style.color = color; }
}

function connect() {
  if (!window.EventSource) { setStatus('Live updates not supported — reload to refresh.'); return; }
  const es = new EventSource('../api/donation_status_stream.php');
  es.addEventListener('status', e => {
    try {
      const data = JSON.parse(e.data);
      if (data.donations && data.donations.length) {
        setStatus('Live — updated ' + new Date().toLocaleTimeString(), '#065f46');
        // Update badge text for each card if status changed
        data.donations.forEach(d => {
          document.querySelectorAll('.track-card').forEach(card => {
            const idEl = card.querySelector('.tc-id');
            if (idEl && idEl.textContent.trim() === d.don_id) {
              const badge = card.querySelector('.badge');
              if (badge) {
                badge.textContent = d.status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
              }
            }
          });
        });
      }
    } catch(_) {}
  });
  es.addEventListener('ping', () => setStatus('Live — ' + new Date().toLocaleTimeString(), '#065f46'));
  es.addEventListener('close', () => { es.close(); setTimeout(connect, 3000); });
  es.onerror = () => { setStatus('Reconnecting…', '#92400e'); es.close(); setTimeout(connect, 5000); };
}
connect();
</script>
</body>
</html>
