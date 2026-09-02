<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';

/* â”€â”€ Guest-friendly shop â€” no login required to browse â”€â”€ */
$is_logged_in = isset($_SESSION['user_email']);
$me   = $is_logged_in ? $_SESSION['user_email'] : '';
$role = $_SESSION['role'] ?? 'donor';

/* AI only for logged-in users */
$ai = null;
if ($is_logged_in) {
    require_once __DIR__ . '/../api/ai_engine.php';
    $ai = adhaar_ai();
}

$cat       = $_GET['cat']       ?? 'all';
$search    = trim($_GET['q']    ?? '');
$sort      = $_GET['sort']      ?? 'newest';
$price_min = isset($_GET['pmin']) && $_GET['pmin'] !== '' ? (float)$_GET['pmin'] : null;
$price_max = isset($_GET['pmax']) && $_GET['pmax'] !== '' ? (float)$_GET['pmax'] : null;
$min_rating= isset($_GET['rating']) && $_GET['rating'] !== '' ? (float)$_GET['rating'] : null;

/* â”€â”€ Whitelist category to prevent injection â”€â”€ */
$allowed_cats = ['handicraft','textile','food_product','jewelry','art','pottery','organic','other'];
if ($cat !== 'all' && !in_array($cat, $allowed_cats, true)) $cat = 'all';

/* â”€â”€ Whitelist sort â”€â”€ */
$allowed_sorts = ['newest','price_low','price_high','popular','rating','discount'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'newest';

/* â”€â”€ Clamp price range to prevent absurd values â”€â”€ */
if ($price_min !== null) $price_min = max(0, min(999999, $price_min));
if ($price_max !== null) $price_max = max(0, min(999999, $price_max));
if ($min_rating !== null) $min_rating = max(0, min(5, $min_rating));

/* â”€â”€ Sanitize search: limit length â”€â”€ */
if (strlen($search) > 200) $search = substr($search, 0, 200);

/* â”€â”€ Log search for AI (logged-in only, non-fatal) â”€â”€ */
if ($search && $ai) {
    try { $ai->logSearch($me, $search, $cat !== 'all' ? $cat : null, 0); } catch(Throwable $e) {}
}

/* â”€â”€ Build WHERE clause â”€â”€ */
$where  = "p.is_active=1 AND s.is_active=1";
$params = [];
$types  = '';

if ($cat !== 'all') {
    $where .= " AND p.category='".mysqli_real_escape_string($conn,$cat)."'";
}
if ($search) {
    $where .= " AND (p.name LIKE '%".mysqli_real_escape_string($conn,$search)."%' OR p.description LIKE '%".mysqli_real_escape_string($conn,$search)."%')";
}
if ($price_min !== null) {
    $where .= " AND p.price >= ".((float)$price_min);
}
if ($price_max !== null) {
    $where .= " AND p.price <= ".((float)$price_max);
}
if ($min_rating !== null) {
    $where .= " AND p.avg_rating >= ".((float)$min_rating);
}

$order_map = [
    'newest'     => 'p.created_at DESC',
    'price_low'  => 'p.price ASC',
    'price_high' => 'p.price DESC',
    'popular'    => 'p.total_sold DESC',
    'rating'     => 'p.avg_rating DESC',
    'discount'   => '((p.mrp - p.price)/NULLIF(p.mrp,0)) DESC',
];
$order_sql = $order_map[$sort] ?? 'p.created_at DESC';

$pq = $conn->query(
    "SELECT p.*, s.store_name, s.village, s.state
     FROM products p
     JOIN seller_stores s ON s.seller_email=p.seller_email
     WHERE $where
     ORDER BY $order_sql"
);
$products = $pq ? $pq->fetch_all(MYSQLI_ASSOC) : [];

/* Update AI search result count (non-fatal) */
if ($search && $ai) {
    try { $conn->query("UPDATE product_search_history SET result_count=".count($products)." WHERE user_email='".mysqli_real_escape_string($conn,$me)."' AND query='".mysqli_real_escape_string($conn,$search)."' ORDER BY searched_at DESC LIMIT 1"); } catch(Throwable $e) {}
}

/* Cart count (logged-in only) */
$cart_count = 0;
if ($is_logged_in) {
    $cart_count = (int)$conn->query("SELECT COUNT(*) c FROM cart WHERE user_email='".mysqli_real_escape_string($conn,$me)."'")->fetch_assoc()['c'];
}

/* AI recommendations */
$ai_recs     = ($ai && !$search) ? ($ai->getProductRecommendations($me,0,6) ?: []) : [];
$show_ai_recs= !empty($ai_recs);

/* Price range of all products (for filter slider) */
$prange = $conn->query("SELECT MIN(price) mn, MAX(price) mx FROM products WHERE is_active=1")->fetch_assoc();
$global_min = (int)($prange['mn'] ?? 0);
$global_max = (int)($prange['mx'] ?? 10000);

$cats = [
    'handicraft'  => 'ðŸŽ¨ Handicraft',
    'textile'     => 'ðŸ§µ Textile',
    'food_product'=> 'ðŸ¯ Food',
    'jewelry'     => 'ðŸ’ Jewelry',
    'art'         => 'ðŸ–¼ï¸ Art',
    'pottery'     => 'ðŸº Pottery',
    'organic'     => 'ðŸŒ¿ Organic',
    'other'       => 'ðŸ“¦ Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SoulServe Shop â€“ Empowering Rural Entrepreneurs</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --navy:#102A43;--teal:#006D77;--green:#2E8B57;--orange:#FF8A00;
  --pink:#F72585;--blue:#2563EB;--bg:#F7FAF9;--card:#fff;
  --border:#E2EBE9;--text:#102A43;--muted:#5A7184;
  --shadow:0 8px 24px rgba(16,42,67,.09);--radius:16px;
  --grad:linear-gradient(135deg,#006D77,#2E8B57);
}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth}
body{background:linear-gradient(180deg,#f5f8f4 0%,#edf4f1 42%,#f8f3ee 100%);color:var(--text);overflow-x:hidden}
/* â”€â”€ HEADER â”€â”€ */
header{position:sticky;top:0;background:rgba(255,255,255,.95);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);box-shadow:0 2px 18px rgba(16,42,67,.07);z-index:200;border-bottom:1px solid rgba(226,235,233,.8)}
.nav{max-width:1200px;margin:auto;padding:0 20px;height:68px;display:flex;align-items:center;justify-content:space-between;gap:14px}
.nav-logo img{height:36px;object-fit:contain;display:block}
.search-bar{flex:1;max-width:400px;display:flex}
.search-bar input{flex:1;padding:11px 16px;border:1.5px solid var(--border);border-right:none;border-radius:10px 0 0 10px;font-size:14px;outline:none;background:#fafaf6}
.search-bar input:focus{border-color:var(--teal)}
.search-bar button{padding:0 16px;background:var(--grad);color:#fff;border:none;border-radius:0 10px 10px 0;cursor:pointer;font-size:15px}
.nav-actions{display:flex;align-items:center;gap:10px}
.nav-actions a,.nav-actions button{text-decoration:none;font-size:13px;font-weight:700;color:var(--muted);padding:8px 12px;border-radius:10px;transition:.2s;background:none;border:none;cursor:pointer}
.nav-actions a:hover,.nav-actions button:hover{color:var(--teal);background:rgba(0,109,119,.07)}
.cart-btn{position:relative;background:var(--grad) !important;color:#fff !important;border-radius:10px !important}
.cart-count{position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;font-size:9px;font-weight:800;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center}
/* â”€â”€ HERO â”€â”€ */
.page{max-width:1200px;margin:0 auto;padding:24px 20px}
.shop-hero{background:linear-gradient(135deg,#102A43 0%,#006D77 55%,#2E8B57 100%);border-radius:22px;padding:28px 32px;color:#fff;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;box-shadow:0 20px 56px rgba(16,42,67,.16)}
.shop-hero h1{font-size:24px;font-weight:900;margin-bottom:6px;letter-spacing:-.3px}
.shop-hero p{font-size:13px;opacity:.9;max-width:480px;line-height:1.6}
.hero-stats{display:flex;gap:16px;flex-wrap:wrap}
.hs{background:rgba(255,255,255,.12);padding:10px 18px;border-radius:12px;text-align:center;border:1px solid rgba(255,255,255,.15)}
.hs-v{font-size:20px;font-weight:900}.hs-l{font-size:10px;opacity:.85;text-transform:uppercase;letter-spacing:.5px}
/* â”€â”€ FILTERS â”€â”€ */
.filters-wrap{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:18px 20px;margin-bottom:20px;border:1px solid var(--border)}
.filter-row1{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.cat-btns{display:flex;gap:7px;flex-wrap:wrap;flex:1}
.cat-btn{padding:7px 14px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:.2s;text-decoration:none;color:var(--muted);white-space:nowrap}
.cat-btn:hover,.cat-btn.active{background:var(--grad);color:#fff;border-color:transparent;box-shadow:0 4px 14px rgba(0,109,119,.2)}
.sort-sel{padding:8px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;color:var(--text);background:#fff;outline:none;cursor:pointer;min-width:160px}
.sort-sel:focus{border-color:var(--teal)}
.filter-row2{display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding-top:12px;border-top:1px solid var(--border)}
.filter-group{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);font-weight:600}
.filter-group label{white-space:nowrap;font-weight:700;color:var(--text)}
.price-input{width:90px;padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;outline:none;color:var(--text)}
.price-input:focus{border-color:var(--teal)}
.rating-sel{padding:7px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;outline:none;cursor:pointer}
.rating-sel:focus{border-color:var(--teal)}
.filter-apply{padding:8px 18px;border-radius:8px;background:var(--grad);color:#fff;border:none;font-size:13px;font-weight:700;cursor:pointer;transition:.2s}
.filter-apply:hover{opacity:.88}
.filter-reset{padding:8px 14px;border-radius:8px;background:var(--bg);color:var(--muted);border:1.5px solid var(--border);font-size:13px;font-weight:600;cursor:pointer;transition:.2s;text-decoration:none;display:inline-flex;align-items:center}
.filter-reset:hover{border-color:var(--teal);color:var(--teal)}
.results-count{font-size:13px;color:var(--muted);font-weight:600;margin-left:auto}
/* â”€â”€ PRODUCTS GRID â”€â”€ */
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:18px}
.prod-card{background:var(--card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);transition:.3s;cursor:pointer;border:1px solid rgba(16,42,67,.05);position:relative}
.prod-card:hover{transform:translateY(-6px);box-shadow:0 20px 52px rgba(16,42,67,.13)}
.prod-img{width:100%;height:176px;object-fit:cover;background:#f0ede5;display:block}
.prod-img-ph{width:100%;height:176px;background:linear-gradient(135deg,#edf3f0,#e5e4dd);display:flex;align-items:center;justify-content:center;font-size:40px}
.prod-body{padding:14px}
.prod-store{font-size:11px;color:var(--muted);margin-bottom:3px}
.prod-name{font-size:14px;font-weight:800;margin-bottom:5px;line-height:1.35;color:var(--text)}
.prod-loc{font-size:11px;color:var(--muted);margin-bottom:7px}
.prod-price-row{display:flex;align-items:baseline;gap:7px;margin-bottom:8px;flex-wrap:wrap}
.prod-price{font-size:18px;font-weight:900;color:var(--teal)}
.prod-mrp{font-size:12px;color:var(--muted);text-decoration:line-through}
.prod-disc{font-size:10px;background:#d1fae5;color:#065f46;padding:2px 7px;border-radius:6px;font-weight:800}
.prod-rating{font-size:12px;color:var(--muted);margin-bottom:10px}
.prod-stock-low{font-size:10px;font-weight:700;color:#d97706;background:#fef3c7;padding:2px 8px;border-radius:6px;display:inline-block;margin-bottom:8px}
.add-cart-btn{width:100%;padding:10px;border:none;border-radius:10px;background:var(--grad);color:#fff;font-size:13px;font-weight:800;cursor:pointer;transition:.25s;box-shadow:0 6px 18px rgba(0,109,119,.15)}
.add-cart-btn:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(46,139,87,.25)}
.add-cart-btn.added{background:linear-gradient(135deg,#059669,#10b981)}
.add-cart-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
/* â”€â”€ EMPTY â”€â”€ */
.empty{text-align:center;padding:60px 24px;background:rgba(255,255,255,.7);border-radius:20px;box-shadow:var(--shadow)}
.empty .emoji{font-size:52px;margin-bottom:12px}
.empty p{color:var(--muted);font-size:14px}
/* â”€â”€ GUEST REGISTRATION MODAL â”€â”€ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:24px;padding:36px 32px;max-width:440px;width:100%;box-shadow:0 32px 80px rgba(0,0,0,.3);animation:modalIn .3s cubic-bezier(.22,1,.36,1);max-height:92vh;overflow-y:auto}
@keyframes modalIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:none}}
.modal-logo{text-align:center;margin-bottom:18px}
.modal-logo img{height:44px;object-fit:contain}
.modal-title{font-size:22px;font-weight:900;color:var(--navy);text-align:center;margin-bottom:6px;font-family:'Inter',sans-serif}
.modal-sub{font-size:13px;color:var(--muted);text-align:center;margin-bottom:24px;line-height:1.6}
.modal-tabs{display:flex;gap:0;background:var(--bg);border-radius:10px;padding:4px;margin-bottom:22px}
.modal-tab{flex:1;padding:9px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;text-align:center;transition:.2s;border:none;background:none;color:var(--muted)}
.modal-tab.active{background:#fff;color:var(--teal);box-shadow:0 2px 8px rgba(16,42,67,.08)}
.modal-form{display:none}.modal-form.active{display:block}
.mf-group{margin-bottom:14px}
.mf-label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px}
.mf-input{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;outline:none;transition:.2s;font-family:inherit;background:#fafaf6}
.mf-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(0,109,119,.1);background:#fff}
.mf-btn{width:100%;padding:13px;border:none;border-radius:10px;background:var(--grad);color:#fff;font-size:14px;font-weight:800;cursor:pointer;transition:.2s;margin-top:6px;box-shadow:0 6px 18px rgba(0,109,119,.2)}
.mf-btn:hover{opacity:.9;transform:translateY(-1px)}
.mf-btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.mf-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:10px 14px;font-size:13px;font-weight:600;margin-bottom:14px;display:none}
.mf-success{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;border-radius:8px;padding:10px 14px;font-size:13px;font-weight:600;margin-bottom:14px;display:none}
.modal-close{position:absolute;top:14px;right:18px;font-size:20px;cursor:pointer;background:none;border:none;color:var(--muted);width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:.2s}
.modal-close:hover{background:var(--bg);color:var(--text)}
.modal-box{position:relative}
.divider-or{display:flex;align-items:center;gap:10px;margin:16px 0;font-size:12px;font-weight:600;color:var(--muted)}
.divider-or::before,.divider-or::after{content:'';flex:1;height:1px;background:var(--border)}
/* â”€â”€ TOAST â”€â”€ */
.shop-toast{position:fixed;bottom:24px;right:24px;background:#102A43;color:#fff;padding:12px 20px;border-radius:12px;font-size:14px;font-weight:600;z-index:9998;transform:translateY(80px);opacity:0;transition:.35s cubic-bezier(.22,1,.36,1);box-shadow:0 18px 42px rgba(16,42,67,.22);max-width:300px;pointer-events:none}
.shop-toast.show{transform:translateY(0);opacity:1}
/* â”€â”€ RESPONSIVE â”€â”€ */
@media(max-width:768px){
  .filter-row2{flex-direction:column;align-items:flex-start;gap:10px}
  .filter-group{flex-wrap:wrap}
  .products-grid{grid-template-columns:repeat(2,1fr);gap:12px}
  .prod-img,.prod-img-ph{height:150px}
  .shop-hero{padding:18px 20px;border-radius:16px}
  .shop-hero h1{font-size:20px}
  .filters-wrap{padding:14px 14px}
  .cat-btn{font-size:11px;padding:6px 12px}
}
@media(max-width:480px){
  .nav{height:56px;padding:0 14px}
  .nav-logo img{height:28px}
  .search-bar{max-width:none}
  .nav{flex-wrap:wrap;height:auto;padding:10px 14px;gap:8px}
  .products-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .prod-img,.prod-img-ph{height:130px}
  .prod-body{padding:10px}
  .prod-name{font-size:13px}
  .prod-price{font-size:16px}
  .add-cart-btn{font-size:12px;padding:9px}
  .modal-box{padding:24px 20px;border-radius:18px}
  .modal-title{font-size:19px}
}
</style>
</head>
<body>
<!-- â•â• HEADER â•â• -->
<header>
  <div class="nav">
    <a href="../index.html" class="nav-logo"><img src="../assets/logo.png" alt="SoulServe"></a>
    <form class="search-bar" method="GET">
      <?php if($cat!=='all'):?><input type="hidden" name="cat" value="<?=htmlspecialchars($cat)?>"> <?php endif;?>
      <input type="text" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Search handmade productsâ€¦" autocomplete="off">
      <button type="submit">ðŸ”</button>
    </form>
    <div class="nav-actions">
      <?php if($is_logged_in): ?>
        <a href="../<?=$role?>/<?=$role?>_dashboard.php">Dashboard</a>
        <a href="my_orders.php">ðŸ“‹ Orders</a>
        <a href="cart.php" class="cart-btn">
          ðŸ›’ Cart
          <?php if($cart_count>0):?><span class="cart-count"><?=$cart_count?></span><?php endif;?>
        </a>
      <?php else: ?>
        <button onclick="openGuestModal('login')" style="color:var(--teal);border:1.5px solid rgba(0,109,119,.3);border-radius:10px;padding:8px 14px">Sign In</button>
        <button onclick="openGuestModal('register')" class="cart-btn" style="padding:8px 14px;border-radius:10px">ðŸ›’ Cart</button>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="page">
  <!-- HERO -->
  <div class="shop-hero">
    <div>
      <h1>ðŸ›ï¸ SoulServe Shop</h1>
      <p>Every purchase empowers a rural artisan, woman entrepreneur, or local craftsperson. Buy with purpose.</p>
    </div>
    <div class="hero-stats">
      <div class="hs"><div class="hs-v"><?=count($products)?></div><div class="hs-l">Products</div></div>
      <?php
        $sellerCount = (int)$conn->query("SELECT COUNT(*) c FROM seller_stores WHERE is_active=1")->fetch_assoc()['c'];
      ?>
      <div class="hs"><div class="hs-v"><?=$sellerCount?></div><div class="hs-l">Artisans</div></div>
    </div>
  </div>

  <!-- FILTERS -->
  <form method="GET" id="filterForm">
    <div class="filters-wrap">
      <!-- Row 1: Category tabs + Sort -->
      <div class="filter-row1">
        <div class="cat-btns">
          <a href="?<?=http_build_query(array_merge($_GET,['cat'=>'all']))?>" class="cat-btn <?=$cat==='all'?'active':''?>">All</a>
          <?php foreach($cats as $v=>$l): ?>
          <a href="?<?=http_build_query(array_merge($_GET,['cat'=>$v]))?>" class="cat-btn <?=$cat===$v?'active':''?>"><?=$l?></a>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="cat" value="<?=htmlspecialchars($cat)?>">
        <input type="hidden" name="q"   value="<?=htmlspecialchars($search)?>">
        <select name="sort" class="sort-sel" onchange="this.form.submit()">
          <option value="newest"     <?=$sort==='newest'    ?'selected':''?>>âœ¨ Newest</option>
          <option value="price_low"  <?=$sort==='price_low' ?'selected':''?>>ðŸ’° Price: Low â†’ High</option>
          <option value="price_high" <?=$sort==='price_high'?'selected':''?>>ðŸ’Ž Price: High â†’ Low</option>
          <option value="popular"    <?=$sort==='popular'   ?'selected':''?>>ðŸ”¥ Most Popular</option>
          <option value="rating"     <?=$sort==='rating'    ?'selected':''?>>â­ Top Rated</option>
          <option value="discount"   <?=$sort==='discount'  ?'selected':''?>>ðŸ·ï¸ Best Discount</option>
        </select>
      </div>
      <!-- Row 2: Price range + Rating filter -->
      <div class="filter-row2">
        <div class="filter-group">
          <label>ðŸ’° Price</label>
          <span style="color:var(--muted);font-size:12px">â‚¹</span>
          <input type="number" name="pmin" class="price-input" placeholder="Min"
            value="<?=$price_min!==null?(int)$price_min:''?>" min="0" max="<?=$global_max?>">
          <span style="color:var(--muted)">â€”</span>
          <input type="number" name="pmax" class="price-input" placeholder="Max"
            value="<?=$price_max!==null?(int)$price_max:''?>" min="0" max="<?=$global_max?>">
        </div>
        <div class="filter-group">
          <label>â­ Rating</label>
          <select name="rating" class="rating-sel">
            <option value="">Any</option>
            <?php foreach([4.5,4,3.5,3] as $r): ?>
            <option value="<?=$r?>" <?=$min_rating==$r?'selected':''?>><?=$r?>+ â­</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="filter-apply">Apply Filters</button>
        <?php if($price_min!==null||$price_max!==null||$min_rating!==null||$search||$cat!=='all'): ?>
        <a href="shop.php" class="filter-reset">âœ• Clear</a>
        <?php endif; ?>
        <span class="results-count"><?=count($products)?> result<?=count($products)!=1?'s':''?></span>
      </div>
    </div>
  </form>

  <!-- PRODUCTS GRID -->
  <?php if(empty($products)): ?>
  <div class="empty">
    <div class="emoji">ðŸ›ï¸</div>
    <p><?=$search?"No products found for \"".htmlspecialchars($search)."\".":'No products found. Try different filters.'?></p>
    <?php if($search||$cat!=='all'||$price_min!==null||$price_max!==null): ?>
    <a href="shop.php" style="display:inline-block;margin-top:14px;color:var(--teal);font-weight:700">Clear filters â†’</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="products-grid" id="productsGrid">
    <?php foreach($products as $p):
      $img = !empty($p['image1']) ? image_url($p['image1']) : null;
      $disc = ($p['mrp']>0 && $p['mrp']>$p['price']) ? round((($p['mrp']-$p['price'])/$p['mrp'])*100) : 0;
    ?>
    <div class="prod-card" onclick="window.location='product.php?id=<?=(int)$p['id']?>'">
      <?php if($img): ?><img src="<?=htmlspecialchars($img)?>" class="prod-img" alt="<?=htmlspecialchars($p['name'])?>" loading="lazy">
      <?php else: ?><div class="prod-img-ph">ðŸ›ï¸</div><?php endif; ?>
      <?php if($disc>0): ?><div style="position:absolute;top:10px;left:10px;background:linear-gradient(135deg,#FF8A00,#F72585);color:#fff;font-size:10px;font-weight:800;padding:3px 9px;border-radius:20px"><?=$disc?>% off</div><?php endif; ?>
      <div class="prod-body">
        <div class="prod-store">ðŸª <?=htmlspecialchars($p['store_name'])?></div>
        <div class="prod-name"><?=htmlspecialchars($p['name'])?></div>
        <?php if($p['village']||$p['state']): ?>
        <div class="prod-loc">ðŸ“ <?=htmlspecialchars(trim(($p['village']?$p['village'].', ':'').$p['state']))?></div>
        <?php endif; ?>
        <div class="prod-price-row">
          <span class="prod-price">â‚¹<?=number_format($p['price'],0)?></span>
          <?php if($p['mrp']>$p['price']): ?>
          <span class="prod-mrp">â‚¹<?=number_format($p['mrp'],0)?></span>
          <?php endif; ?>
        </div>
        <?php if($p['avg_rating']>0): ?>
        <div class="prod-rating">â­ <?=number_format($p['avg_rating'],1)?> Â· <?=(int)$p['total_sold']?> sold</div>
        <?php endif; ?>
        <?php if($p['stock']>0 && $p['stock']<=5): ?>
        <div class="prod-stock-low">âš¡ Only <?=(int)$p['stock']?> left</div>
        <?php endif; ?>
        <button class="add-cart-btn" id="btn-<?=(int)$p['id']?>"
          onclick="addToCart(event,<?=(int)$p['id']?>)"
          <?=$p['stock']<=0?'disabled':''?>>
          <?=$p['stock']<=0?'Out of Stock':'ðŸ›’ Add to Cart'?>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if($show_ai_recs): ?>
<div style="max-width:1200px;margin:32px auto 0;padding:0 20px 40px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px">
    <div>
      <h2 style="font-size:17px;font-weight:800;color:var(--navy);margin-bottom:3px">ðŸ¤– Recommended For You</h2>
      <p style="font-size:12px;color:var(--muted)">AI-personalised based on your browsing history</p>
    </div>
  </div>
  <div class="products-grid">
    <?php foreach($ai_recs as $p):
      $img = !empty($p['image1']) ? image_url($p['image1']) : null;
      $disc= ($p['mrp']>0 && $p['mrp']>$p['price']) ? round((($p['mrp']-$p['price'])/$p['mrp'])*100) : 0;
    ?>
    <div class="prod-card" onclick="window.location='product.php?id=<?=(int)$p['id']?>'">
      <?php if($img): ?><img src="<?=htmlspecialchars($img)?>" class="prod-img" alt="" loading="lazy">
      <?php else: ?><div class="prod-img-ph">ðŸ›ï¸</div><?php endif; ?>
      <div style="position:absolute;top:10px;left:10px;background:rgba(0,109,119,.9);color:#fff;font-size:9px;font-weight:800;padding:3px 8px;border-radius:20px;letter-spacing:.3px">ðŸ¤– AI PICK</div>
      <div class="prod-body">
        <div class="prod-store">ðŸª <?=htmlspecialchars($p['store_name'])?></div>
        <div class="prod-name"><?=htmlspecialchars($p['name'])?></div>
        <div class="prod-price-row">
          <span class="prod-price">â‚¹<?=number_format($p['price'],0)?></span>
          <?php if($p['mrp']>$p['price']): ?><span class="prod-mrp">â‚¹<?=number_format($p['mrp'],0)?></span><?php endif; ?>
        </div>
        <?php if($p['avg_rating']>0): ?><div class="prod-rating">â­ <?=number_format($p['avg_rating'],1)?></div><?php endif; ?>
        <button class="add-cart-btn" id="btn-r-<?=(int)$p['id']?>" onclick="addToCart(event,<?=(int)$p['id']?>)" <?=$p['stock']<=0?'disabled':''?>>
          <?=$p['stock']<=0?'Out of Stock':'ðŸ›’ Add to Cart'?>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- â•â• GUEST REGISTRATION / LOGIN MODAL â•â• -->
<div class="modal-overlay" id="guestModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeGuestModal()">âœ•</button>
    <div class="modal-logo"><img src="../assets/logo.png" alt="SoulServe"></div>
    <div class="modal-title">Join SoulServe</div>
    <p class="modal-sub">Create a free account to add items to your cart, track orders and support rural artisans.</p>

    <div class="modal-tabs">
      <button class="modal-tab active" id="tab-reg" onclick="switchModalTab('register')">Create Account</button>
      <button class="modal-tab" id="tab-login" onclick="switchModalTab('login')">Sign In</button>
    </div>

    <!-- Register form -->
    <div class="modal-form active" id="form-register">
      <div id="reg-error" class="mf-error"></div>
      <div id="reg-success" class="mf-success"></div>
      <div class="mf-group"><label class="mf-label">Full Name *</label>
        <input class="mf-input" type="text" id="reg-name" placeholder="Your name" autocomplete="name" required></div>
      <div class="mf-group"><label class="mf-label">Email Address *</label>
        <input class="mf-input" type="email" id="reg-email" placeholder="you@email.com" autocomplete="email" required></div>
      <div class="mf-group"><label class="mf-label">Password *</label>
        <input class="mf-input" type="password" id="reg-pwd" placeholder="Min. 6 characters" autocomplete="new-password" required></div>
      <div class="mf-group"><label class="mf-label">Phone (optional)</label>
        <input class="mf-input" type="tel" id="reg-phone" placeholder="+91 98765 43210" autocomplete="tel"></div>
      <button class="mf-btn" id="reg-btn" onclick="submitRegister()">Create Account & Continue Shopping â†’</button>
      <p style="text-align:center;font-size:12px;color:var(--muted);margin-top:12px;line-height:1.6">By registering you agree to our Terms of Service. Your account lets you track orders, earn badges and get personalised recommendations.</p>
    </div>

    <!-- Login form -->
    <div class="modal-form" id="form-login">
      <div id="login-error" class="mf-error"></div>
      <div id="login-success" class="mf-success"></div>
      <div class="mf-group"><label class="mf-label">Email Address</label>
        <input class="mf-input" type="email" id="login-email" placeholder="you@email.com" autocomplete="email"></div>
      <div class="mf-group"><label class="mf-label">Password</label>
        <input class="mf-input" type="password" id="login-pwd" placeholder="Your password" autocomplete="current-password"></div>
      <button class="mf-btn" id="login-btn" onclick="submitLogin()">Sign In & Continue Shopping â†’</button>
      <div class="divider-or">or</div>
      <a href="../auth/google_login.php" style="display:flex;align-items:center;justify-content:center;gap:10px;padding:12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;font-weight:600;color:var(--navy);text-decoration:none;transition:.2s" onmouseover="this.style.borderColor='#4285F4'" onmouseout="this.style.borderColor='var(--border)'">
        <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        Continue with Google
      </a>
      <p style="text-align:center;margin-top:14px;font-size:12px;color:var(--muted)"><a href="../auth/forgot.php" style="color:var(--teal);font-weight:600">Forgot password?</a></p>
    </div>
  </div>
</div>

<div id="shopToast" class="shop-toast"></div>

<script>
const IS_LOGGED_IN = <?=$is_logged_in?'true':'false'?>;
<?php if($is_logged_in): ?>
const CSRF = '<?=csrf_token()?>';
<?php else: ?>
const CSRF = '';
<?php endif; ?>

/* â”€â”€ Add to cart â”€â”€ */
function addToCart(e, pid) {
  e.stopPropagation();
  if (!IS_LOGGED_IN) {
    openGuestModal('register');
    return;
  }
  const btns = document.querySelectorAll('#btn-'+pid+',#btn-r-'+pid);
  btns.forEach(b=>{ b.textContent='â³ Addingâ€¦'; b.disabled=true; });
  fetch('../api/cart_action.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=add&product_id='+pid+'&csrf_token='+encodeURIComponent(CSRF)
  })
  .then(r=>r.json())
  .then(d=>{
    if (d.needs_login) { openGuestModal('register'); return; }
    btns.forEach(b=>{ b.disabled=false; });
    if (d.success) {
      btns.forEach(b=>{ b.textContent='âœ… Added!'; b.classList.add('added'); });
      setTimeout(()=>btns.forEach(b=>{ b.textContent='ðŸ›’ Add to Cart'; b.classList.remove('added'); }),2200);
      showToast('Added to cart! ðŸ›’');
      /* update cart badge */
      document.querySelectorAll('.cart-count').forEach(el=>el.textContent=d.cart_count);
    } else {
      btns.forEach(b=>{ b.textContent='ðŸ›’ Add to Cart'; });
      showToast(d.message || 'Could not add to cart');
    }
  })
  .catch(()=>btns.forEach(b=>{ b.textContent='ðŸ›’ Add to Cart'; b.disabled=false; }));
}

/* â”€â”€ Modal â”€â”€ */
let _pendingPid = null;
function openGuestModal(tab) {
  switchModalTab(tab || 'register');
  document.getElementById('guestModal').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeGuestModal() {
  document.getElementById('guestModal').classList.remove('open');
  document.body.style.overflow='';
}
document.getElementById('guestModal').addEventListener('click', e=>{
  if(e.target===document.getElementById('guestModal')) closeGuestModal();
});
function switchModalTab(tab) {
  document.getElementById('form-register').classList.toggle('active', tab==='register');
  document.getElementById('form-login').classList.toggle('active', tab==='login');
  document.getElementById('tab-reg').classList.toggle('active', tab==='register');
  document.getElementById('tab-login').classList.toggle('active', tab==='login');
}

/* â”€â”€ Register â”€â”€ */
function submitRegister() {
  const name  = document.getElementById('reg-name').value.trim();
  const email = document.getElementById('reg-email').value.trim();
  const pwd   = document.getElementById('reg-pwd').value;
  const phone = document.getElementById('reg-phone').value.trim();
  const errEl = document.getElementById('reg-error');
  const sucEl = document.getElementById('reg-success');
  errEl.style.display='none'; sucEl.style.display='none';

  if (!name||!email||!pwd){ errEl.textContent='Please fill in all required fields.'; errEl.style.display='block'; return; }
  if (pwd.length<6){ errEl.textContent='Password must be at least 6 characters.'; errEl.style.display='block'; return; }

  const btn = document.getElementById('reg-btn');
  btn.disabled=true; btn.textContent='Creating accountâ€¦';

  fetch('../api/register_customer.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'name='+encodeURIComponent(name)+'&email='+encodeURIComponent(email)+'&password='+encodeURIComponent(pwd)+'&phone='+encodeURIComponent(phone)
  })
  .then(r=>r.json())
  .then(d=>{
    btn.disabled=false; btn.textContent='Create Account & Continue Shopping â†’';
    if(d.ok){
      sucEl.textContent=d.message; sucEl.style.display='block';
      setTimeout(()=>location.reload(),1200);
    } else {
      errEl.textContent=d.message; errEl.style.display='block';
    }
  })
  .catch(()=>{ btn.disabled=false; btn.textContent='Create Account & Continue Shopping â†’'; errEl.textContent='Network error. Please try again.'; errEl.style.display='block'; });
}
document.getElementById('reg-pwd').addEventListener('keydown',e=>{ if(e.key==='Enter') submitRegister(); });

/* â”€â”€ Login (uses existing login endpoint) â”€â”€ */
function submitLogin() {
  const email = document.getElementById('login-email').value.trim();
  const pwd   = document.getElementById('login-pwd').value;
  const errEl = document.getElementById('login-error');
  const sucEl = document.getElementById('login-success');
  errEl.style.display='none'; sucEl.style.display='none';
  if(!email||!pwd){ errEl.textContent='Please enter email and password.'; errEl.style.display='block'; return; }

  const btn = document.getElementById('login-btn');
  btn.disabled=true; btn.textContent='Signing inâ€¦';

  /* Use register_customer.php with existing credentials â€” it auto-logins on correct password */
  fetch('../api/register_customer.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'email='+encodeURIComponent(email)+'&password='+encodeURIComponent(pwd)+'&name=&phone='
  })
  .then(r=>r.json())
  .then(d=>{
    btn.disabled=false; btn.textContent='Sign In & Continue Shopping â†’';
    if(d.ok){
      sucEl.textContent=d.message||'Signed in!'; sucEl.style.display='block';
      setTimeout(()=>location.reload(),1000);
    } else {
      /* Try actual login.php for existing users */
      const fd = new FormData();
      fd.append('email',email); fd.append('password',pwd);
      /* Redirect to login page with return_url */
      window.location.href='../auth/login.php?redirect='+encodeURIComponent('shop/shop.php');
    }
  })
  .catch(()=>{ btn.disabled=false; window.location.href='../auth/login.php'; });
}
document.getElementById('login-pwd').addEventListener('keydown',e=>{ if(e.key==='Enter') submitLogin(); });

/* â”€â”€ Toast â”€â”€ */
function showToast(msg) {
  const t = document.getElementById('shopToast');
  t.textContent=msg; t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),3200);
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
