<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
$me   = $_SESSION['user_email'];
$role = $_SESSION['role'] ?? 'donor';

$cq = $conn->prepare("SELECT c.*, p.name, p.price, p.image1, p.stock, s.store_name FROM cart c JOIN products p ON p.id=c.product_id JOIN seller_stores s ON s.seller_email=p.seller_email WHERE c.user_email=?");
$cq->bind_param("s",$me); $cq->execute();
$items = $cq->get_result()->fetch_all(MYSQLI_ASSOC);

$subtotal = 0;
foreach($items as $it) $subtotal += $it['price'] * $it['quantity'];
$shipping = $subtotal >= 499 ? 0 : 49;
$total    = $subtotal + $shipping;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cart | Adhaar Shop</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#006D77;--accent2:#2E8B57;--bg:#f5f8f4;--card:#fff;--text:#102A43;--muted:#5A7184;--shadow:0 8px 24px rgba(16,42,67,.08)}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{background:linear-gradient(180deg,#f5f8f4 0%,#edf4f1 42%,#f8f3ee 100%);color:var(--text)}
header{background:#fff;box-shadow:0 2px 12px rgba(16,42,67,.06);position:sticky;top:0;z-index:100}
.nav{max-width:1100px;margin:auto;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between}
.logo{font-size:18px;font-weight:900;color:var(--accent);text-decoration:none;letter-spacing:-.25px}
.nav-links{display:flex;gap:12px;align-items:center}
.nav-links a{color:var(--muted);font-size:13px;font-weight:600;text-decoration:none;padding:8px 12px;border-radius:8px;transition:.2s}
.nav-links a:hover{color:var(--accent)}
.page{max-width:1100px;margin:0 auto;padding:28px 20px}
.page-title{font-size:22px;font-weight:800;margin-bottom:24px}
.cart-layout{display:grid;grid-template-columns:1fr 340px;gap:24px}
/* Items */
.cart-items{background:var(--card);border-radius:16px;box-shadow:var(--shadow);overflow:hidden}
.cart-header{padding:18px 24px;border-bottom:1px solid #f0ede4;font-size:14px;font-weight:700;color:var(--muted)}
.cart-item{display:flex;gap:16px;padding:20px 24px;border-bottom:1px solid #f0ede4;transition:.2s}
.cart-item:last-child{border-bottom:none}
.cart-item:hover{background:#fafaf6}
.item-img{width:80px;height:80px;border-radius:10px;object-fit:cover;background:#f0ede5;flex-shrink:0}
.item-img-ph{width:80px;height:80px;border-radius:10px;background:linear-gradient(135deg,#f0ede5,#e8e4d8);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0}
.item-info{flex:1}
.item-store{font-size:11px;color:var(--muted);margin-bottom:3px}
.item-name{font-size:14px;font-weight:700;margin-bottom:6px}
.item-price{font-size:16px;font-weight:800;color:var(--accent);margin-bottom:10px}
.qty-row{display:flex;align-items:center;gap:8px}
.qty-ctrl{display:flex;align-items:center}
.qty-ctrl button{width:30px;height:30px;border:1.5px solid #e0ddd5;background:#fff;font-size:14px;font-weight:700;cursor:pointer;transition:.2s}
.qty-ctrl button:first-child{border-radius:6px 0 0 6px}
.qty-ctrl button:last-child{border-radius:0 6px 6px 0}
.qty-ctrl button:hover{border-color:var(--accent);color:var(--accent)}
.qty-ctrl span{width:40px;height:30px;border:1.5px solid #e0ddd5;border-left:none;border-right:none;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700}
.remove-btn{font-size:12px;color:#ef4444;background:none;border:none;cursor:pointer;font-weight:600;padding:6px 10px;border-radius:6px;transition:.2s}
.remove-btn:hover{background:#fee2e2}
.item-total{font-size:15px;font-weight:800;white-space:nowrap;color:var(--text)}
/* Summary */
.cart-summary{background:var(--card);border-radius:16px;box-shadow:var(--shadow);padding:24px;height:fit-content;position:sticky;top:84px}
.cart-summary h3{font-size:16px;font-weight:800;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #ede9df}
.sum-row{display:flex;justify-content:space-between;font-size:14px;margin-bottom:12px;color:var(--muted)}
.sum-row.total{font-size:16px;font-weight:800;color:var(--text);margin-top:12px;padding-top:12px;border-top:2px solid #ede9df}
.free-ship{background:#d1fae5;color:#065f46;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:700;margin-bottom:16px;text-align:center}
.checkout-btn{width:100%;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:.3s;box-shadow:0 6px 20px rgba(0,109,119,.18)}
.checkout-btn:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(46,139,87,.22)}
.checkout-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.secure-note{font-size:11px;color:var(--muted);text-align:center;margin-top:10px}
.continue-link{display:block;text-align:center;margin-top:12px;color:var(--accent);font-size:13px;font-weight:600;text-decoration:none}
/* Empty */
.empty-cart{text-align:center;padding:64px 24px;background:var(--card);border-radius:16px;box-shadow:var(--shadow)}
.empty-cart .emoji{font-size:56px;margin-bottom:14px}
.shop-btn{display:inline-block;margin-top:16px;padding:12px 28px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-radius:12px;font-weight:700;text-decoration:none}
@media(max-width:768px){.cart-layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <div class="nav">
    <a href="../index.html" class="logo"><img src="../assets/logo.png" alt="SoulServe" style="height:36px;object-fit:contain;vertical-align:middle"></a>
    <div class="nav-links">
      <a href="shop.php">← Shop</a>
      <a href="my_orders.php">📋 My Orders</a>
    </div>
  </div>
</header>
<div class="page">
  <h1 class="page-title">🛒 My Cart</h1>
  <?php if(empty($items)): ?>
  <div class="empty-cart">
    <div class="emoji">🛒</div>
    <p style="color:var(--muted);font-size:14px">Your cart is empty.</p>
    <a href="shop.php" class="shop-btn">Browse Products →</a>
  </div>
  <?php else: ?>
  <div class="cart-layout">
    <div>
      <div class="cart-items">
        <div class="cart-header"><?=count($items)?> item<?=count($items)>1?'s':''?> in your cart</div>
        <?php foreach($items as $it): $img=!empty($it['image1'])?image_url($it['image1']):null; ?>
        <div class="cart-item" id="ci-<?=(int)$it['product_id']?>">
          <?php if($img): ?><img src="<?=htmlspecialchars($img)?>" class="item-img" alt="">
          <?php else: ?><div class="item-img-ph">🛍️</div><?php endif; ?>
          <div class="item-info">
            <div class="item-store">🏪 <?=htmlspecialchars($it['store_name'])?></div>
            <div class="item-name"><?=htmlspecialchars($it['name'])?></div>
            <div class="item-price">₹<?=number_format($it['price'],2)?></div>
            <div class="qty-row">
              <div class="qty-ctrl">
                <button onclick="updateQty(<?=(int)$it['product_id']?>,-1)">−</button>
                <span id="qty-<?=(int)$it['product_id']?>"><?=(int)$it['quantity']?></span>
                <button onclick="updateQty(<?=(int)$it['product_id']?>,1)">+</button>
              </div>
              <button class="remove-btn" onclick="removeItem(<?=(int)$it['product_id']?>)">🗑 Remove</button>
            </div>
          </div>
          <div class="item-total" id="tot-<?=(int)$it['product_id']?>">₹<?=number_format($it['price']*$it['quantity'],2)?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <div class="cart-summary">
        <h3>Order Summary</h3>
        <?php if($shipping===0): ?>
        <div class="free-ship">🎉 You qualify for FREE delivery!</div>
        <?php endif; ?>
        <div class="sum-row"><span>Subtotal</span><span id="subtotal">₹<?=number_format($subtotal,2)?></span></div>
        <div class="sum-row"><span>Delivery</span><span id="shipping"><?=$shipping===0?'FREE':'₹'.$shipping?></span></div>
        <div class="sum-row total"><span>Total</span><span id="total">₹<?=number_format($total,2)?></span></div>
        <br>
        <button class="checkout-btn" onclick="location.href='checkout.php'">Proceed to Checkout →</button>
        <div class="secure-note">🔒 Secure checkout · COD available</div>
        <a href="shop.php" class="continue-link">← Continue Shopping</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
const csrf = '<?=csrf_token()?>';
const prices = {<?php foreach($items as $it): echo (int)$it['product_id'].':'.floatval($it['price']).','; endforeach; ?>};
const qtys   = {<?php foreach($items as $it): echo (int)$it['product_id'].':'.(int)$it['quantity'].','; endforeach; ?>};

function updateQty(pid, delta) {
  const newQty = Math.max(1, (qtys[pid]||1) + delta);
  qtys[pid] = newQty;
  document.getElementById('qty-'+pid).textContent = newQty;
  document.getElementById('tot-'+pid).textContent = '₹'+((prices[pid]||0)*newQty).toFixed(2);
  recalc();
  fetch('../api/cart_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=update&product_id='+pid+'&quantity='+newQty+'&csrf_token='+encodeURIComponent(csrf)});
}
function removeItem(pid) {
  document.getElementById('ci-'+pid).style.opacity='0.4';
  fetch('../api/cart_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=remove&product_id='+pid+'&csrf_token='+encodeURIComponent(csrf)})
  .then(()=>{ document.getElementById('ci-'+pid).remove(); delete prices[pid]; delete qtys[pid]; recalc(); });
}
function recalc() {
  let sub = 0;
  for(const pid in prices) sub += prices[pid] * (qtys[pid]||1);
  const ship = sub >= 499 ? 0 : 49;
  document.getElementById('subtotal').textContent = '₹'+sub.toFixed(2);
  document.getElementById('shipping').textContent = ship===0 ? 'FREE' : '₹'+ship;
  document.getElementById('total').textContent = '₹'+(sub+ship).toFixed(2);
}
</script>
</body>
</html>
