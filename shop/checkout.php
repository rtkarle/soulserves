<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/upload.php';
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
$me   = $_SESSION['user_email'];

// Handle POST — place order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    csrf_verify();
    $cq = $conn->prepare("SELECT c.*, p.price, p.stock, p.name as pname, p.image1, p.seller_email FROM cart c JOIN products p ON p.id=c.product_id WHERE c.user_email=?");
    $cq->bind_param("s",$me); $cq->execute();
    $cart_items = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($cart_items)) { header("Location: cart.php"); exit; }

    /* ── Stock check BEFORE placing order ── */
    $stock_errors = [];
    foreach ($cart_items as $ci) {
        if ($ci['stock'] < $ci['quantity']) {
            $stock_errors[] = htmlspecialchars($ci['pname']) . ' (only ' . $ci['stock'] . ' left)';
        }
    }
    if (!empty($stock_errors)) {
        $_SESSION['checkout_error'] = 'Some items are out of stock: ' . implode(', ', $stock_errors);
        header("Location: cart.php?error=stock"); exit;
    }

    $subtotal = 0;
    foreach($cart_items as $ci) $subtotal += $ci['price'] * $ci['quantity'];
    $shipping = $subtotal >= 499 ? 0 : 49;
    $total    = $subtotal + $shipping;
    $order_no = 'ADH' . strtoupper(substr(uniqid(),5)) . rand(100,999);

    $sellers = [];
    foreach($cart_items as $ci) {
        $s = $ci['seller_email'];
        if(!isset($sellers[$s])) $sellers[$s] = [];
        $sellers[$s][] = $ci;
    }

    $name    = trim($_POST['s_name']    ?? '');
    $phone   = trim($_POST['s_phone']   ?? '');
    $addr    = trim($_POST['s_address'] ?? '');
    $city    = trim($_POST['s_city']    ?? '');
    $state   = trim($_POST['s_state']   ?? '');
    $pin     = trim($_POST['s_pincode'] ?? '');
    $payment = in_array($_POST['payment']??'',['cod','upi','card']) ? $_POST['payment'] : 'cod';

    /* ── DB Transaction ── */
    $conn->begin_transaction();
    try {
        foreach($sellers as $seller_email => $s_items) {
            $seller_total = 0;
            foreach($s_items as $si) $seller_total += $si['price'] * $si['quantity'];
            $seller_total += (count($sellers)===1) ? $shipping : 0;
            $s_order_no = $order_no . (count($sellers)>1 ? '_'.strtolower(substr($seller_email,0,4)) : '');

            $oq = $conn->prepare("INSERT INTO orders (order_number,buyer_email,seller_email,total_amount,shipping_name,shipping_phone,shipping_address,shipping_city,shipping_state,shipping_pincode,payment_method,order_status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'placed',NOW())");
            $oq->bind_param("sssdsssssss",$s_order_no,$me,$seller_email,$seller_total,$name,$phone,$addr,$city,$state,$pin,$payment);
            $oq->execute();
            $order_id = $conn->insert_id;

            foreach($s_items as $si) {
                $iq = $conn->prepare("INSERT INTO order_items (order_id,product_id,product_name,price,quantity,image) VALUES (?,?,?,?,?,?)");
                $iq->bind_param("iisdis",$order_id,$si['product_id'],$si['pname'],$si['price'],$si['quantity'],$si['image1']);
                $iq->execute();

                /* ── Stock decrement with check ── */
                $upd = $conn->prepare("UPDATE products SET stock=stock-?, total_sold=total_sold+? WHERE id=? AND stock>=?");
                $upd->bind_param("iiii",$si['quantity'],$si['quantity'],$si['product_id'],$si['quantity']);
                $upd->execute();
                if ($upd->affected_rows === 0) {
                    throw new RuntimeException("Stock unavailable for: " . $si['pname']);
                }
            }
        }

        /* ── Clear cart ── */
        $clr = $conn->prepare("DELETE FROM cart WHERE user_email=?");
        $clr->bind_param("s", $me);
        $clr->execute();

        $conn->commit();

    } catch (Throwable $e) {
        $conn->rollback();
        error_log("[checkout] Transaction failed: " . $e->getMessage());
        $_SESSION['checkout_error'] = 'Order failed: ' . htmlspecialchars($e->getMessage()) . '. Please try again.';
        header("Location: cart.php?error=stock"); exit;
    }

    // ── Email notifications ─────────────────────────────────
    require_once __DIR__ . '/../config/mail.php';
    // Fetch buyer name
    $bn = $conn->prepare("SELECT name FROM register WHERE email=?");
    $bn->bind_param("s", $me); $bn->execute();
    $buyer_name = $bn->get_result()->fetch_assoc()['name'] ?? 'Customer';
    $full_address = $addr . ', ' . $city . ', ' . $state . ' – ' . $pin;

    // Buyer confirmation (use all items, first order_no)
    sendOrderConfirmation($me, $buyer_name, $order_no, $cart_items, $total, $full_address, $payment);

    // Seller alert per seller group
    foreach ($sellers as $seller_email => $s_items) {
        $seller_total = 0;
        foreach ($s_items as $si) $seller_total += $si['price'] * $si['quantity'];
        $sn = $conn->prepare("SELECT ss.store_name FROM seller_stores ss WHERE ss.seller_email=?");
        $sn->bind_param("s", $seller_email); $sn->execute();
        $store_name = $sn->get_result()->fetch_assoc()['store_name'] ?? $seller_email;
        $s_order_no = $order_no . (count($sellers) > 1 ? '_' . strtolower(substr($seller_email,0,4)) : '');
        sendSellerOrderAlert($seller_email, $store_name, $s_order_no, $s_items, $seller_total);
    }

    header("Location: my_orders.php?success=1&order=".$order_no); exit;
}

$cq = $conn->prepare("SELECT c.*, p.name, p.price, p.image1, s.store_name FROM cart c JOIN products p ON p.id=c.product_id JOIN seller_stores s ON s.seller_email=p.seller_email WHERE c.user_email=?");
$cq->bind_param("s",$me); $cq->execute();
$items = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
if (empty($items)) { header("Location: shop.php"); exit; }

$uq = $conn->prepare("SELECT name, mobile, address FROM register WHERE email=?");
$uq->bind_param("s",$me); $uq->execute();
$user = $uq->get_result()->fetch_assoc();

$subtotal = 0;
foreach($items as $it) $subtotal += $it['price'] * $it['quantity'];
$shipping = $subtotal >= 499 ? 0 : 49;
$total    = $subtotal + $shipping;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Checkout | Adhaar Shop</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#006D77;--accent2:#2E8B57;--bg:#f5f8f4;--card:#fff;--text:#102A43;--muted:#5A7184;--shadow:0 8px 24px rgba(16,42,67,.08)}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{background:linear-gradient(180deg,#f5f8f4 0%,#edf4f1 42%,#f8f3ee 100%);color:var(--text)}
header{background:#fff;box-shadow:0 2px 12px rgba(16,42,67,.06);position:sticky;top:0;z-index:100}
.nav{max-width:1100px;margin:auto;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between}
.logo{font-size:18px;font-weight:800;color:var(--accent);text-decoration:none}
.back-link{color:var(--muted);font-size:13px;font-weight:600;text-decoration:none}
.back-link:hover{color:var(--accent)}
.page{max-width:1100px;margin:0 auto;padding:28px 20px}
.steps{display:flex;align-items:center;margin-bottom:28px;background:var(--card);border-radius:12px;padding:14px 20px;box-shadow:var(--shadow)}
.step-item{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--muted);flex:1}
.step-item.active{color:var(--accent)}
.step-item.done{color:var(--accent)}
.step-dot{width:28px;height:28px;border-radius:50%;border:2px solid #e0ddd5;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;background:#fff}
.step-item.active .step-dot{border-color:var(--accent);background:var(--accent);color:#fff}
.step-item.done .step-dot{border-color:var(--accent);background:var(--accent);color:#fff}
.step-line{flex:1;height:2px;background:#e0ddd5;margin:0 8px;max-width:60px}
.step-line.done{background:var(--accent)}
.layout{display:grid;grid-template-columns:1fr 340px;gap:24px}
/* Form */
.form-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);padding:28px}
.form-card h3{font-size:16px;font-weight:800;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #ede9df}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px}
.field input,.field select,.field textarea{width:100%;padding:11px 14px;border:1.5px solid #e0ddd5;border-radius:10px;font-size:14px;font-family:inherit;color:var(--text);background:#fafaf6;transition:.2s;outline:none}
.field input:focus,.field select:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(122,125,63,.1)}
.field.full{grid-column:1/-1}
/* Payment */
.payment-options{display:grid;gap:10px;margin-bottom:20px}
.pay-option{display:flex;align-items:center;gap:12px;padding:14px 16px;border:1.5px solid #e0ddd5;border-radius:12px;cursor:pointer;transition:.2s}
.pay-option:hover{border-color:var(--accent)}
.pay-option input[type="radio"]{accent-color:var(--accent)}
.pay-option input:checked ~ .pay-label{color:var(--accent)}
.pay-option:has(input:checked){border-color:var(--accent);background:#f0f0e4}
.pay-label{font-size:14px;font-weight:600}
.pay-sub{font-size:12px;color:var(--muted);margin-top:2px}
.pay-icon{font-size:22px}
/* Summary */
.summary-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);padding:24px;height:fit-content;position:sticky;top:84px}
.summary-card h3{font-size:16px;font-weight:800;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #ede9df}
.sum-item{display:flex;gap:12px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #f0ede4}
.sum-item:last-of-type{border-bottom:none;margin-bottom:0}
.si-img{width:48px;height:48px;border-radius:8px;object-fit:cover;background:#f0ede5}
.si-img-ph{width:48px;height:48px;border-radius:8px;background:linear-gradient(135deg,#f0ede5,#e8e4d8);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.si-info{flex:1}
.si-name{font-size:13px;font-weight:600;margin-bottom:2px}
.si-qty{font-size:11px;color:var(--muted)}
.si-price{font-size:13px;font-weight:700;white-space:nowrap}
.sum-divider{border-top:2px solid #ede9df;margin:16px 0}
.sum-row{display:flex;justify-content:space-between;font-size:13px;color:var(--muted);margin-bottom:8px}
.sum-row.total{font-size:16px;font-weight:800;color:var(--text);margin-top:8px;padding-top:8px;border-top:2px solid #ede9df}
.place-btn{width:100%;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:15px;font-weight:700;cursor:pointer;margin-top:16px;transition:.3s;box-shadow:0 6px 20px rgba(0,109,119,.18)}
.place-btn:hover{transform:translateY(-2px)}
.secure-info{text-align:center;font-size:11px;color:var(--muted);margin-top:8px}
/* Delivery estimate banner */
.del-banner{background:#d1fae5;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#065f46;font-weight:600;display:flex;align-items:center;gap:8px}
@media(max-width:768px){.layout{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <div class="nav">
    <a href="../index.html" class="logo"><img src="../assets/logo.png" alt="SoulServe" style="height:36px;object-fit:contain;vertical-align:middle"></a>
    <a href="cart.php" class="back-link">← Back to Cart</a>
  </div>
</header>
<div class="page">
  <div class="steps">
    <div class="step-item done"><div class="step-dot">✓</div>Cart</div>
    <div class="step-line done"></div>
    <div class="step-item active"><div class="step-dot">2</div>Checkout</div>
    <div class="step-line"></div>
    <div class="step-item"><div class="step-dot">3</div>Confirmation</div>
  </div>

  <form method="POST">
    <?=csrf_field()?><input type="hidden" name="place_order" value="1">
    <div class="layout">
      <div>
        <!-- Delivery Address -->
        <div class="form-card" style="margin-bottom:20px">
          <h3>📦 Delivery Address</h3>
          <div class="del-banner">🚚 Estimated delivery: 5–8 business days after order confirmation</div>
          <div class="form-grid">
            <div class="field">
              <label>Full Name *</label>
              <input type="text" name="s_name" value="<?=htmlspecialchars($user['name']??'')?>" required placeholder="Recipient's full name">
            </div>
            <div class="field">
              <label>Phone Number *</label>
              <input type="tel" name="s_phone" value="<?=htmlspecialchars($user['mobile']??'')?>" required placeholder="10-digit mobile">
            </div>
            <div class="field full">
              <label>Full Address *</label>
              <input type="text" name="s_address" required placeholder="House no., Street, Area, Landmark">
            </div>
            <div class="field">
              <label>City / Town *</label>
              <input type="text" name="s_city" required placeholder="City or Town">
            </div>
            <div class="field">
              <label>State *</label>
              <input type="text" name="s_state" required placeholder="State">
            </div>
            <div class="field">
              <label>Pincode *</label>
              <input type="text" name="s_pincode" required pattern="[0-9]{6}" maxlength="6" placeholder="6-digit pincode">
            </div>
          </div>
        </div>

        <!-- Payment -->
        <div class="form-card">
          <h3>💳 Payment Method</h3>
          <div class="payment-options">
            <label class="pay-option">
              <input type="radio" name="payment" value="cod" checked>
              <span class="pay-icon">💵</span>
              <div><div class="pay-label">Cash on Delivery (COD)</div><div class="pay-sub">Pay when your order arrives</div></div>
            </label>
            <label class="pay-option">
              <input type="radio" name="payment" value="upi">
              <span class="pay-icon">📱</span>
              <div><div class="pay-label">UPI / QR Code</div><div class="pay-sub">Pay via PhonePe, GPay, Paytm, etc.</div></div>
            </label>
            <label class="pay-option">
              <input type="radio" name="payment" value="card">
              <span class="pay-icon">💳</span>
              <div><div class="pay-label">Credit / Debit Card</div><div class="pay-sub">Visa, Mastercard, RuPay</div></div>
            </label>
          </div>
          <div style="background:#fef3c7;border-radius:10px;padding:12px 16px;font-size:12px;color:#92400e">
            💡 All orders support 7-day returns. Your purchase directly empowers rural sellers and artisans.
          </div>
        </div>
      </div>

      <!-- Order Summary -->
      <div>
        <div class="summary-card">
          <h3>Order Summary (<?=count($items)?> items)</h3>
          <?php foreach($items as $it): $img=!empty($it['image1'])?image_url($it['image1']):null; ?>
          <div class="sum-item">
            <?php if($img): ?><img src="<?=htmlspecialchars($img)?>" class="si-img" alt="">
            <?php else: ?><div class="si-img-ph">🛍️</div><?php endif; ?>
            <div class="si-info">
              <div class="si-name"><?=htmlspecialchars($it['name'])?></div>
              <div class="si-qty">Qty: <?=(int)$it['quantity']?> · <?=htmlspecialchars($it['store_name'])?></div>
            </div>
            <div class="si-price">₹<?=number_format($it['price']*$it['quantity'],2)?></div>
          </div>
          <?php endforeach; ?>
          <div class="sum-divider"></div>
          <div class="sum-row"><span>Subtotal</span><span>₹<?=number_format($subtotal,2)?></span></div>
          <div class="sum-row"><span>Delivery</span><span><?=$shipping===0?'<span style="color:#065f46;font-weight:700">FREE</span>':'₹'.$shipping?></span></div>
          <div class="sum-row total"><span>Total</span><span>₹<?=number_format($total,2)?></span></div>
          <button type="submit" class="place-btn">🛒 Place Order →</button>
          <div class="secure-info">🔒 Secure · 7-day returns · COD available</div>
        </div>
      </div>
    </div>
  </form>
</div>
</body>
</html>
