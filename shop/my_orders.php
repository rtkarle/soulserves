<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/../config/db.php';
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
$me   = $_SESSION['user_email'];
$role = $_SESSION['role'] ?? 'donor';

// Handle review submit
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_review'])) {
    csrf_verify();
    $oid = (int)$_POST['order_id'];
    $pid = (int)$_POST['product_id'];
    $rating = min(5,max(1,(int)$_POST['rating']));
    $txt = trim($_POST['review_text']);
    // Verify buyer owns order
    $vq = $conn->prepare("SELECT id FROM orders WHERE id=? AND buyer_email=? AND order_status='delivered'");
    $vq->bind_param("is",$oid,$me); $vq->execute();
    if($vq->get_result()->num_rows===1) {
        $rq = $conn->prepare("INSERT IGNORE INTO product_reviews(product_id,order_id,reviewer_email,rating,review_text) VALUES(?,?,?,?,?)");
        $rq->bind_param("iiiss",$pid,$oid,$me,$rating,$txt);
        $rq->execute();
        // Update avg rating
        $conn->query("UPDATE products p SET avg_rating=(SELECT AVG(rating) FROM product_reviews WHERE product_id=$pid) WHERE p.id=$pid");
    }
    header("Location: my_orders.php?tab=delivered&msg=review"); exit;
}
// Handle return submit
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_return'])) {
    csrf_verify();
    $oid = (int)$_POST['order_id'];
    $pid = (int)$_POST['product_id'];
    $reason = $_POST['reason'] ?? 'other';
    $desc   = trim($_POST['return_desc'] ?? '');
    $oq = $conn->prepare("SELECT seller_email FROM orders WHERE id=? AND buyer_email=? AND order_status='delivered'");
    $oq->bind_param("is",$oid,$me); $oq->execute();
    $ord = $oq->get_result()->fetch_assoc();
    if($ord) {
        /* ── Enforce 7-day return window ── */
        $order_age_days = (int)$conn->query("SELECT DATEDIFF(NOW(),updated_at) d FROM orders WHERE id=$oid")->fetch_assoc()['d'];
        if ($order_age_days > 7) {
            header("Location: my_orders.php?tab=delivered&msg=return_expired"); exit;
        }
        $rq = $conn->prepare("INSERT INTO return_requests(order_id,product_id,buyer_email,seller_email,reason,description,status) VALUES(?,?,?,?,?,?,'requested')");
        $rq->bind_param("iissss",$oid,$pid,$me,$ord['seller_email'],$reason,$desc);
        $rq->execute();
        $conn->query("UPDATE orders SET order_status='return_requested' WHERE id=$oid");
    }
    header("Location: my_orders.php?tab=delivered&msg=return"); exit;
}

$tab = $_GET['tab'] ?? 'all';
$msg = $_GET['msg'] ?? '';
$success_order = $_GET['order'] ?? '';

$where = "o.buyer_email='".mysqli_real_escape_string($conn,$me)."'";
$status_filter = ['all'=>'','active'=>"AND o.order_status NOT IN ('delivered','cancelled','returned','return_requested')","delivered"=>"AND o.order_status IN ('delivered','return_requested','returned')","cancelled"=>"AND o.order_status='cancelled'"];
$where .= $status_filter[$tab] ?? '';

$oq = $conn->query("SELECT o.*, GROUP_CONCAT(oi.product_name SEPARATOR '|') as item_names, GROUP_CONCAT(oi.product_id SEPARATOR '|') as item_ids, GROUP_CONCAT(oi.image SEPARATOR '|') as item_imgs, GROUP_CONCAT(oi.quantity SEPARATOR '|') as item_qtys, GROUP_CONCAT(oi.price SEPARATOR '|') as item_prices, GROUP_CONCAT(oi.id SEPARATOR '|') as item_ids2, s.store_name FROM orders o JOIN order_items oi ON oi.order_id=o.id LEFT JOIN seller_stores s ON s.seller_email=o.seller_email WHERE $where GROUP BY o.id ORDER BY o.created_at DESC");
$orders = $oq->fetch_all(MYSQLI_ASSOC);

// Check which items are already reviewed
$reviewed = [];
$rrev = $conn->query("SELECT product_id, order_id FROM product_reviews WHERE reviewer_email='".mysqli_real_escape_string($conn,$me)."'");
while($r=$rrev->fetch_assoc()) $reviewed[$r['order_id'].'_'.$r['product_id']] = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Orders | Adhaar Shop</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#006D77;--accent2:#2E8B57;--bg:#f5f8f4;--card:#fff;--text:#102A43;--muted:#5A7184;--shadow:0 8px 24px rgba(16,42,67,.08)}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{background:linear-gradient(180deg,#f5f8f4 0%,#edf4f1 42%,#f8f3ee 100%);color:var(--text)}
header{background:#fff;box-shadow:0 2px 12px rgba(16,42,67,.06);position:sticky;top:0;z-index:100}
.nav{max-width:1000px;margin:auto;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between}
.logo{font-size:18px;font-weight:800;color:var(--accent);text-decoration:none}
.nav-links a{color:var(--muted);font-size:13px;font-weight:600;text-decoration:none;padding:8px 12px;border-radius:8px;transition:.2s}
.nav-links a:hover{color:var(--accent)}
.page{max-width:1000px;margin:0 auto;padding:28px 20px}
.page-title{font-size:22px;font-weight:800;margin-bottom:20px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px}
.tab-btn{padding:9px 18px;border-radius:20px;border:1.5px solid #e0ddd5;background:#fff;font-size:13px;font-weight:600;cursor:pointer;color:var(--muted);text-decoration:none;transition:.2s}
.tab-btn:hover,.tab-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.alert{padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:20px}
.alert-success{background:#d1fae5;color:#065f46}
.order-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.order-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:16px 20px;border-bottom:1px solid #f0ede4;background:#fafaf6}
.order-no{font-size:14px;font-weight:800}
.order-date{font-size:12px;color:var(--muted)}
.pill{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.pill.placed{background:#dbeafe;color:#1e40af}
.pill.confirmed,.pill.processing{background:#fef3c7;color:#92400e}
.pill.shipped{background:#ede9fe;color:#5b21b6}
.pill.out_for_delivery{background:#fce7f3;color:#9d174d}
.pill.delivered{background:#d1fae5;color:#065f46}
.pill.cancelled,.pill.returned{background:#fee2e2;color:#991b1b}
.pill.return_requested{background:#fce7f3;color:#9d174d}
.order-body{padding:20px}
.order-items{margin-bottom:16px}
.oi-row{display:flex;gap:14px;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #f0ede4}
.oi-row:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
.oi-img{width:64px;height:64px;border-radius:10px;object-fit:cover;background:#f0ede5;flex-shrink:0}
.oi-img-ph{width:64px;height:64px;border-radius:10px;background:linear-gradient(135deg,#f0ede5,#e8e4d8);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
.oi-info{flex:1}
.oi-name{font-size:14px;font-weight:700;margin-bottom:3px}
.oi-meta{font-size:12px;color:var(--muted)}
.oi-price{font-size:14px;font-weight:700;white-space:nowrap}
/* Tracking */
.tracking-bar{background:#f8f7f0;border-radius:12px;padding:16px 20px;margin-bottom:14px}
.tracking-bar h4{font-size:13px;font-weight:700;margin-bottom:12px;color:var(--muted)}
.tl-steps{display:flex;align-items:center}
.tl-step{display:flex;flex-direction:column;align-items:center;font-size:10px;color:var(--muted);flex:1;text-align:center;gap:4px}
.tl-dot{width:14px;height:14px;border-radius:50%;background:#d4d0c4}
.tl-step.done .tl-dot{background:var(--accent)}
.tl-step.done{color:var(--accent);font-weight:700}
.tl-line{flex:1;height:2px;background:#d4d0c4;margin-bottom:18px}
.tl-line.done{background:var(--accent)}
.order-footer{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding-top:14px;border-top:1px solid #f0ede4}
.order-total{font-size:15px;font-weight:800}
.order-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{padding:8px 16px;border-radius:10px;border:none;font-size:13px;font-weight:700;cursor:pointer;transition:.2s}
.btn-review{background:#fef3c7;color:#92400e}.btn-review:hover{background:#fde68a}
.btn-return{background:#fee2e2;color:#991b1b}.btn-return:hover{background:#fca5a5}
.btn-track{background:#ede9fe;color:#5b21b6}.btn-track:hover{background:#ddd6fe}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:20px;padding:28px;max-width:480px;width:calc(100% - 32px);max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.25)}
.modal h3{font-size:18px;font-weight:800;margin-bottom:6px}
.modal p{font-size:13px;color:var(--muted);margin-bottom:20px}
.modal .field{margin-bottom:14px}
.modal .field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px}
.modal .field input,.modal .field select,.modal .field textarea{width:100%;padding:10px 13px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:14px;font-family:inherit;outline:none;background:#fafaf6;transition:.2s}
.modal .field input:focus,.modal .field select:focus,.modal .field textarea:focus{border-color:var(--accent);background:#fff}
.modal .field textarea{resize:vertical;min-height:80px}
.star-select{display:flex;gap:6px;font-size:28px;cursor:pointer;margin-bottom:4px}
.star-select span{transition:.1s;color:#d1d5db}.star-select span.lit{color:#f59e0b}
.modal-btns{display:flex;gap:10px;margin-top:16px}
.btn-submit{flex:1;padding:12px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:14px;font-weight:700;cursor:pointer}
.btn-cancel{padding:12px 20px;border:1.5px solid #e0ddd5;border-radius:12px;background:#fff;color:var(--muted);font-size:14px;font-weight:600;cursor:pointer}
.empty{text-align:center;padding:64px 24px;background:var(--card);border-radius:16px;box-shadow:var(--shadow)}
.empty .emoji{font-size:52px;margin-bottom:14px}
</style>
</head>
<body>
<header>
  <div class="nav">
    <a href="../index.html" class="logo"><img src="../assets/logo.png" alt="SoulServe" style="height:36px;object-fit:contain;vertical-align:middle"></a>
    <div class="nav-links">
      <a href="shop.php">🛍️ Shop</a>
      <a href="cart.php">🛒 Cart</a>
    </div>
  </div>
</header>
<div class="page">
  <h1 class="page-title">📋 My Orders</h1>

  <?php if($msg==='review'): ?><div class="alert alert-success">⭐ Review submitted successfully!</div>
  <?php elseif($msg==='return'): ?><div class="alert alert-success">↩️ Return request submitted! The seller will review it shortly.</div>
  <?php elseif($msg==='return_expired'): ?><div class="alert alert-error" style="background:#fee2e2;color:#991b1b;">⚠️ Return window has expired. Returns are only accepted within 7 days of delivery.</div>
  <?php elseif($success_order): ?><div class="alert alert-success">🎉 Order placed successfully! Order #<?=htmlspecialchars($success_order)?></div>
  <?php endif; ?>

  <div class="tabs">
    <a href="?tab=all"       class="tab-btn <?=$tab==='all'      ?'active':''?>">All Orders</a>
    <a href="?tab=active"    class="tab-btn <?=$tab==='active'   ?'active':''?>">Active</a>
    <a href="?tab=delivered" class="tab-btn <?=$tab==='delivered'?'active':''?>">Delivered</a>
    <a href="?tab=cancelled" class="tab-btn <?=$tab==='cancelled'?'active':''?>">Cancelled</a>
  </div>

  <?php if(empty($orders)): ?>
  <div class="empty">
    <div class="emoji">📦</div>
    <p style="color:var(--muted);font-size:14px">No orders found.</p>
    <a href="shop.php" style="display:inline-block;margin-top:16px;padding:11px 24px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-radius:12px;font-weight:700;text-decoration:none">Browse Shop →</a>
  </div>
  <?php else: ?>
  <?php
  $status_steps=['placed','confirmed','shipped','out_for_delivery','delivered'];
  foreach($orders as $o):
    $names = explode('|',$o['item_names']??'');
    $ids   = explode('|',$o['item_ids']??'');
    $imgs  = explode('|',$o['item_imgs']??'');
    $qtys  = explode('|',$o['item_qtys']??'');
    $prices= explode('|',$o['item_prices']??'');
    $cur   = array_search($o['order_status'],$status_steps);
    if($cur===false) $cur = -1;
  ?>
  <div class="order-card">
    <div class="order-head">
      <div>
        <div class="order-no"><?=htmlspecialchars($o['order_number'])?></div>
        <div class="order-date">Placed: <?=date('d M Y · h:i A',strtotime($o['created_at']))?> · <?=htmlspecialchars($o['store_name']??'Seller')?></div>
      </div>
      <span class="pill <?=htmlspecialchars($o['order_status'])?>"><?=ucfirst(str_replace('_',' ',$o['order_status']))?></span>
    </div>
    <div class="order-body">
      <!-- Items -->
      <div class="order-items">
        <?php foreach($names as $i=>$nm): $img=!empty($imgs[$i])?'../'.$imgs[$i]:null; ?>
        <div class="oi-row">
          <?php if($img): ?><img src="<?=htmlspecialchars($img)?>" class="oi-img" alt="">
          <?php else: ?><div class="oi-img-ph">🛍️</div><?php endif; ?>
          <div class="oi-info">
            <div class="oi-name"><?=htmlspecialchars($nm)?></div>
            <div class="oi-meta">Qty: <?=htmlspecialchars($qtys[$i]??1)?> · ₹<?=number_format((float)($prices[$i]??0),2)?> each</div>
          </div>
          <div class="oi-price">₹<?=number_format((float)($prices[$i]??0)*(int)($qtys[$i]??1),2)?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Tracking -->
      <?php if(!in_array($o['order_status'],['cancelled','returned'])): ?>
      <div class="tracking-bar">
        <h4>📦 Order Tracking</h4>
        <div class="tl-steps">
          <?php foreach($status_steps as $si=>$s): ?>
          <div class="tl-step <?=$si<=$cur?'done':''?>">
            <div class="tl-dot"></div>
            <span><?=ucfirst(str_replace('_',' ',$s))?></span>
          </div>
          <?php if($si<count($status_steps)-1): ?><div class="tl-line <?=$si<$cur?'done':''?>"></div><?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if(!empty($o['tracking_id'])): ?>
        <div style="margin-top:10px;font-size:12px;color:var(--muted)">Tracking ID: <strong><?=htmlspecialchars($o['tracking_id'])?></strong></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="order-footer">
        <div>
          <div class="order-total">Total: ₹<?=number_format($o['total_amount'],2)?></div>
          <div style="font-size:12px;color:var(--muted)">Payment: <?=strtoupper($o['payment_method'])?> · <?=htmlspecialchars($o['shipping_city'])?>, <?=htmlspecialchars($o['shipping_state'])?></div>
        </div>
        <div class="order-actions">
          <?php if($o['order_status']==='delivered'): ?>
            <?php foreach($ids as $i=>$pid): if(!isset($reviewed[$o['id'].'_'.$pid])): ?>
            <button class="btn btn-review" onclick="openReview(<?=(int)$o['id']?>,<?=(int)$pid?>,'<?=htmlspecialchars(addslashes($names[$i]))?>',<?=$i?>)">⭐ Review</button>
            <?php endif; endforeach; ?>
            <?php if($o['order_status']==='delivered'): ?>
            <button class="btn btn-return" onclick="openReturn(<?=(int)$o['id']?>,<?=(int)($ids[0]??0)?>)">↩️ Return</button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Review Modal -->
<div class="modal-overlay" id="reviewModal">
  <div class="modal">
    <h3>⭐ Write a Review</h3>
    <p id="reviewProductName" style="color:var(--text);font-weight:600;margin-bottom:8px"></p>
    <form method="POST">
      <?=csrf_field()?>
      <input type="hidden" name="submit_review" value="1">
      <input type="hidden" name="order_id" id="rev_order_id">
      <input type="hidden" name="product_id" id="rev_product_id">
      <div class="field">
        <label>Your Rating *</label>
        <div class="star-select" id="starSelect">
          <span onclick="setRating(1)">★</span><span onclick="setRating(2)">★</span>
          <span onclick="setRating(3)">★</span><span onclick="setRating(4)">★</span>
          <span onclick="setRating(5)">★</span>
        </div>
        <input type="hidden" name="rating" id="ratingInput" value="5">
      </div>
      <div class="field">
        <label>Review (optional)</label>
        <textarea name="review_text" placeholder="Share your experience with this product..."></textarea>
      </div>
      <div class="modal-btns">
        <button type="button" class="btn-cancel" onclick="closeModal('reviewModal')">Cancel</button>
        <button type="submit" class="btn-submit">Submit Review →</button>
      </div>
    </form>
  </div>
</div>

<!-- Return Modal -->
<div class="modal-overlay" id="returnModal">
  <div class="modal">
    <h3>↩️ Request Return</h3>
    <p>Returns are accepted within 7 days of delivery. Item must be unused and in original condition.</p>
    <form method="POST">
      <?=csrf_field()?>
      <input type="hidden" name="submit_return" value="1">
      <input type="hidden" name="order_id" id="ret_order_id">
      <input type="hidden" name="product_id" id="ret_product_id">
      <div class="field">
        <label>Reason for Return *</label>
        <select name="reason">
          <option value="damaged">Product arrived damaged</option>
          <option value="wrong_item">Wrong item received</option>
          <option value="not_as_described">Not as described</option>
          <option value="changed_mind">Changed my mind</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="field">
        <label>Description</label>
        <textarea name="return_desc" placeholder="Describe the issue in detail..."></textarea>
      </div>
      <div style="background:#fef3c7;border-radius:10px;padding:12px;font-size:12px;color:#92400e;margin-bottom:14px">
        📦 After your request is approved, a pickup will be scheduled. Refund is initiated once item is received.
      </div>
      <div class="modal-btns">
        <button type="button" class="btn-cancel" onclick="closeModal('returnModal')">Cancel</button>
        <button type="submit" class="btn-submit">Submit Return Request →</button>
      </div>
    </form>
  </div>
</div>

<script>
let curRating = 5;
function setRating(r) {
  curRating = r;
  document.getElementById('ratingInput').value = r;
  document.querySelectorAll('#starSelect span').forEach((s,i)=>{
    s.classList.toggle('lit', i<r);
  });
}
setRating(5);
function openReview(oid, pid, name, idx) {
  document.getElementById('rev_order_id').value = oid;
  document.getElementById('rev_product_id').value = pid;
  document.getElementById('reviewProductName').textContent = name;
  document.getElementById('reviewModal').classList.add('open');
}
function openReturn(oid, pid) {
  document.getElementById('ret_order_id').value = oid;
  document.getElementById('ret_product_id').value = pid;
  document.getElementById('returnModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
</script>
</body>
</html>
