<?php
/* ── Load PHPMailer — composer autoload preferred, local fallback for XAMPP ── */
$_phpmailer_loaded = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $_phpmailer_loaded = true;
} elseif (file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
    $_phpmailer_loaded = true;
} else {
    error_log('PHPMailer not found — emails will be silently skipped.');
}

if (!defined('MAIL_USERNAME')) {
    require_once __DIR__ . '/config.php';
}

function sendMail(string $to, string $subject, string $body): bool {
    global $_phpmailer_loaded;
    if (!$_phpmailer_loaded) return false;   /* no crash — silent skip */

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>','<br/>'], "\n", $body));
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log("Mailer Error to $to: " . $mail->ErrorInfo);
        return false;
    }
}

function sendOTPMail(string $to, string $otp): bool {
    $subject = "Your OTP – Adhaar Verification";
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f6f5f0;font-family:Inter,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f0;padding:40px 20px;">
  <tr><td align="center">
    <table width="100%" style="max-width:520px;background:#fff;border-radius:24px;overflow:hidden;">
      <tr><td style="background:linear-gradient(135deg,#7a7d3f,#9a8f5c);padding:36px 40px;text-align:center;">
        <h1 style="margin:0;color:#fff;font-size:24px;font-weight:800;">🌿 Adhaar – The SoulServe</h1>
        <p style="margin:8px 0 0;color:rgba(255,255,255,.85);font-size:14px;">Email Verification</p>
      </td></tr>
      <tr><td style="padding:40px 44px;">
        <p style="margin:0 0 28px;font-size:14px;color:#5a594d;line-height:1.7;">Use the OTP below to verify your email and complete registration.</p>
        <div style="background:#f6f5f0;border:2px dashed #9a8f5c;border-radius:16px;padding:28px;text-align:center;margin-bottom:28px;">
          <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#9a8f5c;letter-spacing:2px;text-transform:uppercase;">Your OTP Code</p>
          <p style="margin:0;font-size:42px;font-weight:900;color:#7a7d3f;letter-spacing:12px;">' . htmlspecialchars($otp) . '</p>
        </div>
        <div style="background:#fef3c7;border-radius:10px;padding:14px 18px;margin-bottom:24px;">
          <p style="margin:0;font-size:13px;color:#92400e;font-weight:600;">⏱ Valid for <strong>10 minutes</strong> only.</p>
        </div>
      </td></tr>
      <tr><td style="background:#f6f5f0;padding:24px 44px;border-top:1px solid #ede9df;text-align:center;">
        <p style="margin:0;font-size:12px;color:#9a8f5c;">© 2026 Adhaar – The SoulServe | Kopargaon, Maharashtra</p>
      </td></tr>
    </table>
  </td></tr>
</table></body></html>';
    return sendMail($to, $subject, $body);
}

function sendStatusNotification(string $donorEmail, string $type, string $status, array $details = []): bool {
    $statusLabels = [
        'accepted'      => ['label'=>'Accepted ✅',         'color'=>'#065f46','bg'=>'#d1fae5','msg'=>'Your donation has been reviewed and accepted. We will schedule a pickup soon.'],
        'rejected'      => ['label'=>'Rejected ❌',         'color'=>'#991b1b','bg'=>'#fee2e2','msg'=>'Unfortunately your donation could not be accepted at this time.'],
        'scheduled'     => ['label'=>'Pickup Scheduled 📅', 'color'=>'#1e40af','bg'=>'#dbeafe','msg'=>'A volunteer has been assigned and your pickup is scheduled.'],
        'out_for_pickup'=> ['label'=>'Out for Pickup 🚚',   'color'=>'#9d174d','bg'=>'#fce7f3','msg'=>'Our volunteer is on the way to collect your donation.'],
        'picked_up'     => ['label'=>'Picked Up 📦',        'color'=>'#5b21b6','bg'=>'#ede9fe','msg'=>'Your donation has been picked up and is being processed.'],
        'delivered'     => ['label'=>'Delivered 🤝',        'color'=>'#065f46','bg'=>'#d1fae5','msg'=>'Your donation has been successfully delivered to people in need. Thank you for your kindness! 🙏'],
    ];
    if (!isset($statusLabels[$status])) return false;
    $s = $statusLabels[$status];

    // ── Build extra detail rows ───────────────────────────────
    $extraRows = '';
    if (!empty($details['pickup_date']))
        $extraRows .= '<tr><td style="padding:8px 0;font-size:14px;color:#5a594d;border-bottom:1px solid #f0ede5"><strong>Pickup Date:</strong> ' . htmlspecialchars($details['pickup_date']) . '</td></tr>';
    if (!empty($details['pickup_time']))
        $extraRows .= '<tr><td style="padding:8px 0;font-size:14px;color:#5a594d;border-bottom:1px solid #f0ede5"><strong>Pickup Time:</strong> ' . htmlspecialchars($details['pickup_time']) . '</td></tr>';
    if (!empty($details['volunteer_email']))
        $extraRows .= '<tr><td style="padding:8px 0;font-size:14px;color:#5a594d;border-bottom:1px solid #f0ede5"><strong>Volunteer:</strong> ' . htmlspecialchars($details['volunteer_email']) . '</td></tr>';
    if (!empty($details['beneficiary_count']))
        $extraRows .= '<tr><td style="padding:8px 0;font-size:14px;color:#5a594d;border-bottom:1px solid #f0ede5"><strong>Beneficiaries Served:</strong> ' . (int)$details['beneficiary_count'] . ' people</td></tr>';
    if (!empty($details['delivery_note']))
        $extraRows .= '<tr><td style="padding:8px 0;font-size:14px;color:#5a594d"><strong>Delivery Note:</strong> ' . htmlspecialchars($details['delivery_note']) . '</td></tr>';

    // ── Proof image block (only for delivered status) ─────────
    $proofBlock = '';
    if ($status === 'delivered' && !empty($details['proof_image_url'])) {
        $imgUrl = htmlspecialchars($details['proof_image_url']);
        $proofBlock = '
        <div style="margin:24px 0;border-radius:16px;overflow:hidden;border:2px solid #bbf7d0">
          <div style="background:#d1fae5;padding:12px 18px;display:flex;align-items:center;gap:8px">
            <span style="font-size:1.1rem">📸</span>
            <strong style="font-size:13px;color:#065f46">Proof of Delivery</strong>
          </div>
          <div style="padding:4px;background:#f0fdf4">
            <img src="' . $imgUrl . '" alt="Delivery proof"
                 style="width:100%;max-height:280px;object-fit:cover;border-radius:10px;display:block">
          </div>
          <div style="background:#d1fae5;padding:10px 18px;text-align:center">
            <span style="font-size:12px;color:#065f46;font-weight:600">✓ This photo was taken by the volunteer at the time of delivery</span>
          </div>
        </div>';
    }

    // ── Celebration banner for delivered ─────────────────────
    $celebrationBanner = '';
    if ($status === 'delivered') {
        $celebrationBanner = '
        <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-radius:14px;padding:20px 24px;margin-bottom:20px;text-align:center;border:1px solid #a7f3d0">
          <div style="font-size:2.2rem;margin-bottom:8px">🎉</div>
          <h2 style="margin:0 0 6px;font-size:20px;font-weight:900;color:#065f46">Mission Accomplished!</h2>
          <p style="margin:0;font-size:13px;color:#047857;line-height:1.6">Your generous donation has reached people who truly needed it.<br>You made a real difference today. 💚</p>
        </div>';
    }

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{font-family:Inter,Arial,sans-serif}</style>
</head>
<body style="margin:0;padding:0;background:#f6f5f0;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f0;padding:40px 20px;">
  <tr><td align="center">
    <table width="100%" style="max-width:540px;background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 8px 32px rgba(47,46,38,.12)">

      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#1e1d18,#7a7d3f,#9a8f5c);padding:32px 40px;text-align:center;">
        <h1 style="margin:0;color:#fff;font-size:22px;font-weight:800;letter-spacing:-.3px">🌿 Adhaar – The SoulServe</h1>
        <p style="margin:8px 0 0;color:rgba(255,255,255,.8);font-size:13px">Donation Status Update</p>
      </td></tr>

      <!-- Body -->
      <tr><td style="padding:36px 40px;">

        <!-- Status badge -->
        <div style="background:' . $s['bg'] . ';border-radius:14px;padding:18px 24px;margin-bottom:22px;text-align:center;border:1px solid ' . $s['color'] . '22">
          <p style="margin:0;font-size:20px;font-weight:900;color:' . $s['color'] . '">' . $s['label'] . '</p>
        </div>

        <p style="margin:0 0 6px;font-size:14px;color:#5a594d;line-height:1.7">
          Your <strong>' . ucfirst($type) . ' donation</strong> status has been updated.
        </p>
        <p style="margin:0 0 20px;font-size:14px;color:#5a594d;line-height:1.7">' . $s['msg'] . '</p>

        ' . $celebrationBanner . '

        ' . ($extraRows ? '<table width="100%" style="border-top:2px solid #f0ede5;margin-bottom:20px;border-radius:10px;overflow:hidden">' . $extraRows . '</table>' : '') . '

        ' . $proofBlock . '

        <!-- CTA -->
        <div style="text-align:center;margin-top:28px">
          <a href="' . APP_URL . '/donor/track.php"
             style="display:inline-block;padding:13px 32px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;letter-spacing:.2px">
            📍 Track Your Donation →
          </a>
        </div>

      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f6f5f0;padding:20px 40px;border-top:1px solid #ede9df;text-align:center;">
        <p style="margin:0 0 4px;font-size:12px;color:#9a8f5c;font-weight:600">© 2026 Adhaar – The SoulServe</p>
        <p style="margin:0;font-size:11px;color:#b8b5a8">Kopargaon, Maharashtra · adhaarsoulserve@gmail.com</p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>';

    return sendMail($donorEmail, "Donation Update: " . $s['label'] . " – Adhaar", $body);
}

// ═══════════════════════════════════════════════════════════════════════════
//  ADDITIONAL NOTIFICATION FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════

/* ── Shared HTML shell ───────────────────────────────────────────────────── */
function _mail_wrap(string $header_title, string $header_sub, string $body_html): string {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{font-family:Inter,Arial,sans-serif}</style></head>
<body style="margin:0;padding:0;background:#f6f5f0;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f0;padding:40px 20px;">
<tr><td align="center">
<table width="100%" style="max-width:540px;background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 8px 32px rgba(47,46,38,.12)">
<tr><td style="background:linear-gradient(135deg,#1e1d18,#7a7d3f,#9a8f5c);padding:30px 40px;text-align:center;">
  <h1 style="margin:0;color:#fff;font-size:21px;font-weight:800;letter-spacing:-.3px">🌿 Adhaar – The SoulServe</h1>
  <p style="margin:7px 0 0;color:rgba(255,255,255,.8);font-size:13px;">' . $header_sub . '</p>
</td></tr>
<tr><td style="padding:34px 40px;">' . $body_html . '</td></tr>
<tr><td style="background:#f6f5f0;padding:18px 40px;border-top:1px solid #ede9df;text-align:center;">
  <p style="margin:0 0 3px;font-size:12px;color:#9a8f5c;font-weight:600;">© 2026 Adhaar – The SoulServe</p>
  <p style="margin:0;font-size:11px;color:#b8b5a8">Kopargaon, Maharashtra · adhaarsoulserve@gmail.com</p>
</td></tr>
</table>
</td></tr></table></body></html>';
}

/* ── 1. Welcome email after registration ─────────────────────────────────── */
function sendWelcomeMail(string $to, string $name, string $role): bool {
    $role_label = ['donor'=>'Donor 🎁','volunteer'=>'Volunteer 🤝','seller'=>'Seller 🏪'][$role] ?? ucfirst($role);
    $role_msg   = [
        'donor'     => 'You can now donate food & clothing, track every pickup, and shop from our rural artisan marketplace.',
        'volunteer' => 'You will receive task requests from admin to pick up and deliver donations. Accept or decline from your dashboard.',
        'seller'    => 'Set up your store, list your products, and start selling directly to buyers across India — no middlemen.',
    ][$role] ?? 'Explore the platform and get started today.';

    $role_link  = ['donor'=>APP_URL.'/donor/donor_dashboard.php','volunteer'=>APP_URL.'/volunteer/volunteer_dashboard.php','seller'=>APP_URL.'/seller/seller_dashboard.php'][$role] ?? APP_URL;

    $body = '
    <p style="margin:0 0 18px;font-size:22px;text-align:center;">🎉</p>
    <h2 style="margin:0 0 8px;font-size:19px;font-weight:800;color:#2f2e26;text-align:center">Welcome, ' . htmlspecialchars($name) . '!</h2>
    <p style="margin:0 0 22px;font-size:14px;color:#5a594d;line-height:1.7;text-align:center">Your account is active as <strong>' . $role_label . '</strong>.</p>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:18px 22px;margin-bottom:24px;">
      <p style="margin:0;font-size:14px;color:#065f46;line-height:1.7">' . $role_msg . '</p>
    </div>
    <div style="text-align:center;margin-top:10px">
      <a href="' . $role_link . '" style="display:inline-block;padding:13px 30px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">Go to My Dashboard →</a>
    </div>';

    return sendMail($to, "Welcome to Adhaar – The SoulServe! 🌿", _mail_wrap("Welcome!", "Account Activated", $body));
}

/* ── 2. Donation received confirmation ───────────────────────────────────── */
function sendDonationReceived(string $to, string $name, string $type, string $qty, string $address): bool {
    $icon  = $type === 'food' ? '🍱' : '👕';
    $label = $type === 'food' ? 'Food Donation' : 'Clothing Donation';

    $body = '
    <p style="margin:0 0 6px;font-size:15px;color:#2f2e26;font-weight:700">Hello ' . htmlspecialchars($name) . ',</p>
    <p style="margin:0 0 22px;font-size:14px;color:#5a594d;line-height:1.7">We\'ve received your donation request. Our team will review and schedule a pickup shortly.</p>
    <div style="background:#fef9ee;border:1px solid #fde68a;border-radius:14px;padding:20px 24px;margin-bottom:22px;">
      <div style="font-size:2rem;margin-bottom:10px">' . $icon . '</div>
      <table width="100%">
        <tr><td style="padding:6px 0;font-size:13px;color:#5a594d;border-bottom:1px solid #fde68a"><strong>Type:</strong> ' . $label . '</td></tr>
        <tr><td style="padding:6px 0;font-size:13px;color:#5a594d;border-bottom:1px solid #fde68a"><strong>Quantity:</strong> ' . htmlspecialchars($qty) . '</td></tr>
        <tr><td style="padding:6px 0;font-size:13px;color:#5a594d"><strong>Pickup Address:</strong> ' . htmlspecialchars($address) . '</td></tr>
      </table>
    </div>
    <p style="margin:0 0 22px;font-size:13px;color:#5a594d;line-height:1.7">You will receive email updates at every step — acceptance, scheduling, pickup, and delivery. You can also track your donation anytime.</p>
    <div style="text-align:center">
      <a href="' . APP_URL . '/donor/track.php" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">Track My Donation →</a>
    </div>';

    return sendMail($to, $icon . " Donation Received – We'll be in touch soon! | Adhaar", _mail_wrap("Donation Received", "Thank you for your generosity 🙏", $body));
}

/* ── 3. Order confirmation — buyer ───────────────────────────────────────── */
function sendOrderConfirmation(string $to, string $name, string $order_no, array $items, float $total, string $address, string $payment): bool {
    $rows = '';
    foreach ($items as $it) {
        $rows .= '<tr>
          <td style="padding:9px 0;font-size:13px;color:#2f2e26;border-bottom:1px solid #f0ede5">' . htmlspecialchars($it['pname'] ?? $it['product_name'] ?? '—') . '</td>
          <td style="padding:9px 0;font-size:13px;color:#5a594d;border-bottom:1px solid #f0ede5;text-align:center">' . (int)$it['quantity'] . '</td>
          <td style="padding:9px 0;font-size:13px;font-weight:700;border-bottom:1px solid #f0ede5;text-align:right">₹' . number_format($it['price'] * $it['quantity'], 2) . '</td>
        </tr>';
    }
    $pay_label = ['cod'=>'💵 Cash on Delivery','upi'=>'📱 UPI','card'=>'💳 Card'][$payment] ?? strtoupper($payment);

    $body = '
    <p style="margin:0 0 6px;font-size:15px;color:#2f2e26;font-weight:700">Hello ' . htmlspecialchars($name) . ',</p>
    <p style="margin:0 0 20px;font-size:14px;color:#5a594d;line-height:1.7">Your order has been placed successfully! The seller will confirm and dispatch soon.</p>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
      <span style="font-size:1.2rem">🛒</span>
      <div><strong style="font-size:14px;color:#065f46">Order #' . htmlspecialchars($order_no) . '</strong><br><span style="font-size:12px;color:#047857">Estimated delivery: 5–8 business days</span></div>
    </div>
    <table width="100%" style="margin-bottom:20px">
      <thead><tr>
        <th style="text-align:left;font-size:11px;color:#9a8f5c;font-weight:700;text-transform:uppercase;padding-bottom:8px;border-bottom:2px solid #ede9df">Product</th>
        <th style="text-align:center;font-size:11px;color:#9a8f5c;font-weight:700;text-transform:uppercase;padding-bottom:8px;border-bottom:2px solid #ede9df">Qty</th>
        <th style="text-align:right;font-size:11px;color:#9a8f5c;font-weight:700;text-transform:uppercase;padding-bottom:8px;border-bottom:2px solid #ede9df">Amount</th>
      </tr></thead>
      <tbody>' . $rows . '</tbody>
      <tfoot><tr>
        <td colspan="2" style="padding-top:10px;font-size:15px;font-weight:800;color:#2f2e26">Total</td>
        <td style="padding-top:10px;font-size:15px;font-weight:800;color:#7a7d3f;text-align:right">₹' . number_format($total, 2) . '</td>
      </tr></tfoot>
    </table>
    <table width="100%" style="background:#f6f5f0;border-radius:12px;padding:14px 18px;margin-bottom:22px">
      <tr><td style="font-size:13px;color:#5a594d;padding:5px 0"><strong>📍 Delivery Address:</strong> ' . htmlspecialchars($address) . '</td></tr>
      <tr><td style="font-size:13px;color:#5a594d;padding:5px 0"><strong>💳 Payment:</strong> ' . $pay_label . '</td></tr>
    </table>
    <div style="text-align:center">
      <a href="' . APP_URL . '/shop/my_orders.php" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">Track My Order →</a>
    </div>';

    return sendMail($to, "🛒 Order Confirmed #" . $order_no . " – Adhaar Shop", _mail_wrap("Order Placed!", "Your order is confirmed ✅", $body));
}

/* ── 4. New order alert — seller ─────────────────────────────────────────── */
function sendSellerOrderAlert(string $to, string $store_name, string $order_no, array $items, float $total): bool {
    $rows = '';
    foreach ($items as $it) {
        $rows .= '<tr>
          <td style="padding:8px 0;font-size:13px;color:#2f2e26;border-bottom:1px solid #f0ede5">' . htmlspecialchars($it['pname'] ?? $it['product_name'] ?? '—') . '</td>
          <td style="padding:8px 0;font-size:13px;text-align:center;border-bottom:1px solid #f0ede5">' . (int)$it['quantity'] . '</td>
          <td style="padding:8px 0;font-size:13px;font-weight:700;text-align:right;border-bottom:1px solid #f0ede5">₹' . number_format($it['price'] * $it['quantity'], 2) . '</td>
        </tr>';
    }
    $body = '
    <p style="margin:0 0 6px;font-size:15px;color:#2f2e26;font-weight:700">Hello ' . htmlspecialchars($store_name) . ',</p>
    <p style="margin:0 0 20px;font-size:14px;color:#5a594d;line-height:1.7">🎉 You have a <strong>new order</strong>! Please confirm and dispatch it promptly.</p>
    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:14px 18px;margin-bottom:20px;">
      <strong style="font-size:14px;color:#92400e">Order #' . htmlspecialchars($order_no) . '</strong>
    </div>
    <table width="100%" style="margin-bottom:20px">
      <thead><tr>
        <th style="text-align:left;font-size:11px;color:#9a8f5c;font-weight:700;text-transform:uppercase;padding-bottom:8px;border-bottom:2px solid #ede9df">Product</th>
        <th style="text-align:center;font-size:11px;color:#9a8f5c;font-weight:700;text-transform:uppercase;padding-bottom:8px;border-bottom:2px solid #ede9df">Qty</th>
        <th style="text-align:right;font-size:11px;color:#9a8f5c;font-weight:700;text-transform:uppercase;padding-bottom:8px;border-bottom:2px solid #ede9df">Amount</th>
      </tr></thead>
      <tbody>' . $rows . '</tbody>
      <tfoot><tr>
        <td colspan="2" style="padding-top:10px;font-size:15px;font-weight:800;color:#2f2e26">Your Earnings</td>
        <td style="padding-top:10px;font-size:15px;font-weight:800;color:#7a7d3f;text-align:right">₹' . number_format($total, 2) . '</td>
      </tr></tfoot>
    </table>
    <div style="text-align:center">
      <a href="' . APP_URL . '/seller/seller_dashboard.php?tab=orders" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">Manage Orders →</a>
    </div>';

    return sendMail($to, "🛍️ New Order #" . $order_no . " – Adhaar Shop", _mail_wrap("New Order!", "You have a new order to fulfil 🎉", $body));
}

/* ── 5. Order status update — buyer ──────────────────────────────────────── */
function sendOrderStatusUpdate(string $to, string $name, string $order_no, string $status, string $tracking_id = ''): bool {
    $labels = [
        'confirmed'       => ['🏭 Order Confirmed',      '#1e40af','#dbeafe', 'Your order has been confirmed by the seller and is being prepared.'],
        'processing'      => ['⚙️ Being Processed',      '#5b21b6','#ede9fe', 'Your order is being packed and prepared for dispatch.'],
        'shipped'         => ['🚚 Order Shipped',         '#1e40af','#dbeafe', 'Your order is on its way! Track using the ID below.'],
        'out_for_delivery'=> ['🛵 Out for Delivery',      '#9d174d','#fce7f3', 'Your order is out for delivery today. Stay available!'],
        'delivered'       => ['✅ Order Delivered',        '#065f46','#d1fae5', 'Your order has been delivered. Enjoy your purchase! 🎉'],
        'cancelled'       => ['❌ Order Cancelled',        '#991b1b','#fee2e2', 'Your order has been cancelled. If this was unexpected, please contact us.'],
        'return_requested'=> ['↩️ Return Requested',      '#92400e','#fef3c7', 'Your return request is being reviewed by the seller.'],
        'returned'        => ['💰 Refund Initiated',      '#065f46','#d1fae5', 'Your return has been approved. Refund will be processed within 5–7 days.'],
    ];
    if (!isset($labels[$status])) return false;
    [$label, $color, $bg, $msg] = $labels[$status];

    $tracking_block = '';
    if ($status === 'shipped' && $tracking_id) {
        $tracking_block = '<div style="background:#f6f5f0;border-radius:10px;padding:12px 18px;margin-bottom:18px;font-size:13px;color:#2f2e26">
          <strong>📦 Tracking ID:</strong> ' . htmlspecialchars($tracking_id) . '
        </div>';
    }

    $celebrate = $status === 'delivered' ? '<p style="text-align:center;font-size:2rem;margin:0 0 12px">🎉</p>' : '';

    $body = '
    <p style="margin:0 0 6px;font-size:15px;color:#2f2e26;font-weight:700">Hello ' . htmlspecialchars($name) . ',</p>
    <p style="margin:0 0 20px;font-size:14px;color:#5a594d;line-height:1.7">Your order status has been updated.</p>
    ' . $celebrate . '
    <div style="background:' . $bg . ';border-radius:12px;padding:16px 22px;margin-bottom:18px;text-align:center;border:1px solid ' . $color . '33">
      <p style="margin:0;font-size:18px;font-weight:800;color:' . $color . '">' . $label . '</p>
      <p style="margin:6px 0 0;font-size:13px;color:' . $color . '">Order #' . htmlspecialchars($order_no) . '</p>
    </div>
    <p style="margin:0 0 18px;font-size:14px;color:#5a594d;line-height:1.7">' . $msg . '</p>
    ' . $tracking_block . '
    <div style="text-align:center">
      <a href="' . APP_URL . '/shop/my_orders.php" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">View My Orders →</a>
    </div>';

    return sendMail($to, "Order Update: " . $label . " – Adhaar Shop", _mail_wrap("Order Update", "Status changed for Order #" . $order_no, $body));
}

/* ── 6. Volunteer welcome ─────────────────────────────────────────────────── */
function sendVolunteerWelcome(string $to, string $name, string $city): bool {
    $body = '
    <p style="margin:0 0 8px;font-size:22px;text-align:center">🤝</p>
    <h2 style="margin:0 0 8px;font-size:19px;font-weight:800;color:#2f2e26;text-align:center">Thank you, ' . htmlspecialchars($name) . '!</h2>
    <p style="margin:0 0 22px;font-size:14px;color:#5a594d;line-height:1.7;text-align:center">Your volunteer application for <strong>' . htmlspecialchars($city) . '</strong> has been received.</p>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:18px 22px;margin-bottom:22px">
      <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#065f46">What happens next?</p>
      <ul style="margin:0;padding-left:18px;font-size:13px;color:#047857;line-height:1.9">
        <li>Admin will review your application</li>
        <li>You will receive task assignments via email</li>
        <li>Accept or decline pickups from your volunteer dashboard</li>
        <li>Every delivery you complete helps a family in need</li>
      </ul>
    </div>
    <div style="text-align:center">
      <a href="' . APP_URL . '/auth/register.php" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">Register / Login →</a>
    </div>';

    return sendMail($to, "🤝 Volunteer Application Received – Adhaar", _mail_wrap("Application Received!", "You're making a difference 💚", $body));
}

/* ── 7. Seller store verified ────────────────────────────────────────────── */
function sendSellerVerified(string $to, string $name, string $store_name): bool {
    $body = '
    <p style="margin:0 0 8px;font-size:22px;text-align:center">🏪</p>
    <h2 style="margin:0 0 8px;font-size:19px;font-weight:800;color:#2f2e26;text-align:center">Congratulations, ' . htmlspecialchars($name) . '!</h2>
    <p style="margin:0 0 22px;font-size:14px;color:#5a594d;line-height:1.7;text-align:center">Your store <strong>' . htmlspecialchars($store_name) . '</strong> has been <span style="color:#065f46;font-weight:700">verified ✓</span> by our admin team.</p>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:18px 22px;margin-bottom:22px">
      <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#065f46">You can now:</p>
      <ul style="margin:0;padding-left:18px;font-size:13px;color:#047857;line-height:1.9">
        <li>List unlimited products on Adhaar Shop</li>
        <li>Receive orders directly from buyers across India</li>
        <li>Track sales, earnings, and settlements from your dashboard</li>
        <li>Accept returns and manage your store profile</li>
      </ul>
    </div>
    <div style="text-align:center">
      <a href="' . APP_URL . '/seller/seller_dashboard.php" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">Go to Seller Dashboard →</a>
    </div>';

    return sendMail($to, "🏪 Your store is Verified – Adhaar Shop", _mail_wrap("Store Verified!", $store_name . " is now live on Adhaar Shop ✓", $body));
}

/* ── 8. Admin alert on new contact message ───────────────────────────────── */
function sendAdminContactAlert(string $from_name, string $from_email, string $message): bool {
    $admin_email = defined('MAIL_USERNAME') ? MAIL_USERNAME : 'rtkarle03@gmail.com';
    $body = '
    <p style="margin:0 0 16px;font-size:15px;color:#2f2e26;font-weight:700">📬 New Contact Message</p>
    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:16px 20px;margin-bottom:18px">
      <table width="100%">
        <tr><td style="padding:6px 0;font-size:13px;color:#5a594d;border-bottom:1px solid #fde68a"><strong>Name:</strong> ' . htmlspecialchars($from_name) . '</td></tr>
        <tr><td style="padding:6px 0;font-size:13px;color:#5a594d;border-bottom:1px solid #fde68a"><strong>Email:</strong> <a href="mailto:' . htmlspecialchars($from_email) . '" style="color:#7a7d3f">' . htmlspecialchars($from_email) . '</a></td></tr>
        <tr><td style="padding:6px 0;font-size:13px;color:#5a594d"><strong>Time:</strong> ' . date('d M Y · h:i A') . '</td></tr>
      </table>
    </div>
    <div style="background:#f6f5f0;border-left:4px solid #7a7d3f;border-radius:8px;padding:16px 20px;margin-bottom:22px">
      <p style="margin:0;font-size:13px;color:#5a594d;line-height:1.7;font-style:italic">"' . htmlspecialchars($message) . '"</p>
    </div>
    <div style="text-align:center">
      <a href="' . APP_URL . '/admin/admin_dashboard.php?tab=contacts" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;">View in Admin Panel →</a>
    </div>';

    return sendMail($admin_email, "📬 New Contact Message from " . $from_name . " – Adhaar", _mail_wrap("New Message", "Someone sent a message via the contact form", $body));
}
