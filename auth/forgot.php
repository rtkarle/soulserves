<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $chk = $conn->prepare("SELECT id FROM register WHERE email=? AND verified=1");
        $chk->bind_param("s", $email);
        $chk->execute();
        if ($chk->get_result()->num_rows === 1) {
            $token = bin2hex(random_bytes(32));
            // Delete old tokens for this email — proper MySQLi bind_param
            $del = $conn->prepare("DELETE FROM password_resets WHERE email=?");
            $del->bind_param("s", $email);
            $del->execute();
            $ins = $conn->prepare("INSERT INTO password_resets(email,token,created_at) VALUES(?,?,NOW())");
            $ins->bind_param("ss", $email, $token);
            $ins->execute();
            $link = APP_URL . '/auth/reset.php?token=' . $token;
            $body = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f6f5f0;font-family:Inter,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f0;padding:40px 20px;"><tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:24px;overflow:hidden;">
<tr><td style="background:linear-gradient(135deg,#006D77,#2E8B57);padding:32px 40px;text-align:center;">
<h1 style="margin:0;color:#fff;font-size:22px;font-weight:800;">🌿 Adhaar – The SoulServe</h1>
<p style="margin:8px 0 0;color:rgba(255,255,255,.85);font-size:13px;">Password Reset Request</p></td></tr>
<tr><td style="padding:36px 40px;">
<p style="font-size:14px;color:#5a594d;line-height:1.7;margin-bottom:24px;">We received a request to reset your password. Click the button below to set a new one. This link is valid for <strong>30 minutes</strong>.</p>
<div style="text-align:center;margin-bottom:24px;">
<a href="' . $link . '" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#7a7d3f,#9a8f5c);color:#fff;border-radius:12px;font-weight:700;font-size:15px;text-decoration:none;">Reset My Password →</a></div>
<p style="font-size:12px;color:#9a8f5c;">If you did not request this, ignore this email. Your password will not change.</p>
</td></tr>
<tr><td style="background:#f6f5f0;padding:20px 40px;border-top:1px solid #ede9df;text-align:center;">
<p style="margin:0;font-size:12px;color:#9a8f5c;">© 2026 Adhaar – The SoulServe</p></td></tr>
</table></td></tr></table></body></html>';
            sendMail($email, 'Reset Your Password – Adhaar', $body);
        }
        // Always show success (prevents email enumeration)
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password | Adhaar</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#006D77;--accent2:#2E8B57;--text:#102A43;--muted:#5A7184}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#2f2e26,#4a4a30,#2f2e26);display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;width:100%;max-width:420px;padding:48px 44px;border-radius:28px;box-shadow:0 40px 100px rgba(0,0,0,.35);animation:fadeUp .5s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
.brand{text-align:center;margin-bottom:32px}
.brand-icon{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 14px}
.brand h1{font-size:22px;font-weight:800;color:var(--text)}
.brand p{font-size:13px;color:var(--muted);margin-top:5px;line-height:1.6}
.field{margin-bottom:20px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px}
.field input{width:100%;padding:13px 16px;border:2px solid #e5e3d8;border-radius:12px;font-size:14px;color:var(--text);background:#fafaf6;transition:.25s;outline:none}
.field input:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(122,125,63,.1)}
.btn{width:100%;padding:14px;border:none;border-radius:50px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 12px 30px rgba(122,125,63,.4);transition:.3s}
.btn:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(122,125,63,.55)}
.success-box{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:16px;border-radius:12px;font-size:14px;font-weight:600;margin-bottom:20px;text-align:center;line-height:1.6}
.error-box{background:#fee2e2;color:#991b1b;padding:12px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:18px;text-align:center}
.back{display:block;text-align:center;margin-top:18px;color:var(--muted);text-decoration:none;font-size:13px;font-weight:600}
.back:hover{color:var(--accent)}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-icon"><img src="../assets/logo.png" alt="SoulServe" style="width:52px;height:52px;object-fit:contain;border-radius:0"></div>
    <h1>Forgot Password?</h1>
    <p>Enter your registered email and we'll send you a secure reset link.</p>
  </div>
  <?php if ($sent): ?>
    <div class="success-box">✅ If that email is registered, you'll receive a reset link shortly.<br><small style="font-weight:500;opacity:.85">Check your spam folder too.</small></div>
    <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none;margin-top:4px">← Back to Login</a>
  <?php else: ?>
    <?php if ($error): ?><div class="error-box">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@example.com" required autocomplete="email">
      </div>
      <button class="btn" type="submit">Send Reset Link →</button>
    </form>
    <a href="login.php" class="back">← Back to Login</a>
  <?php endif; ?>
</div>
</body>
</html>
