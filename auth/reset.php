<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;

// Validate token (30-minute window)
$valid_token = null;
if ($token) {
    $tq = $conn->prepare("SELECT * FROM password_resets WHERE token=? AND created_at >= NOW() - INTERVAL 30 MINUTE");
    $tq->bind_param("s", $token);
    $tq->execute();
    $valid_token = $tq->get_result()->fetch_assoc();
}

if (!$valid_token) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    csrf_verify();
    $new  = $_POST['password']  ?? '';
    $conf = $_POST['confirm']   ?? '';
    if (strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new !== $conf) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $up = $conn->prepare("UPDATE register SET password=? WHERE email=?");
        $up->bind_param("ss", $hash, $valid_token['email']);
        $up->execute();
        // Delete used token — proper MySQLi bind_param
        $del_tok = $conn->prepare("DELETE FROM password_resets WHERE email=?");
        $del_tok->bind_param("s", $valid_token['email']);
        $del_tok->execute();
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Password | Adhaar</title>
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
.brand p{font-size:13px;color:var(--muted);margin-top:5px}
.field{margin-bottom:20px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px}
.field input{width:100%;padding:13px 16px;border:2px solid #e5e3d8;border-radius:12px;font-size:14px;color:var(--text);background:#fafaf6;transition:.25s;outline:none}
.field input:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(122,125,63,.1)}
.strength-bar{height:4px;background:#e0ddd5;border-radius:4px;margin-top:6px;overflow:hidden}
.strength-fill{height:100%;border-radius:4px;width:0%;transition:.3s}
.strength-txt{font-size:11px;color:var(--muted);margin-top:4px;font-weight:600}
.btn{width:100%;padding:14px;border:none;border-radius:50px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 12px 30px rgba(122,125,63,.4);transition:.3s}
.btn:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(122,125,63,.55)}
.success-box{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:16px;border-radius:12px;font-size:14px;font-weight:600;margin-bottom:20px;text-align:center}
.error-box{background:#fee2e2;color:#991b1b;padding:12px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:18px;text-align:center}
.back{display:block;text-align:center;margin-top:18px;color:var(--muted);text-decoration:none;font-size:13px;font-weight:600}
.back:hover{color:var(--accent)}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-icon"><img src="../assets/logo.png" alt="SoulServe" style="width:52px;height:52px;object-fit:contain;border-radius:0"></div>
    <h1>Set New Password</h1>
    <p>Choose a strong new password for your account.</p>
  </div>

  <?php if ($success): ?>
    <div class="success-box">✅ Password reset successfully!</div>
    <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none;margin-top:4px">Login with New Password →</a>
  <?php elseif ($error && !$valid_token): ?>
    <div class="error-box">⚠ <?= htmlspecialchars($error) ?></div>
    <a href="forgot.php" class="btn" style="display:block;text-align:center;text-decoration:none;margin-top:4px">Request New Link →</a>
  <?php else: ?>
    <?php if ($error): ?><div class="error-box">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <div class="field">
        <label>New Password</label>
        <input type="password" name="password" id="pwdInput" placeholder="At least 8 characters" required>
        <div class="strength-bar"><div class="strength-fill" id="sFill"></div></div>
        <div class="strength-txt" id="sTxt"></div>
      </div>
      <div class="field">
        <label>Confirm New Password</label>
        <input type="password" name="confirm" placeholder="Repeat new password" required>
      </div>
      <button class="btn" type="submit">Reset Password →</button>
    </form>
    <a href="login.php" class="back">← Back to Login</a>
  <?php endif; ?>
</div>
<script>
const pwd=document.getElementById('pwdInput'),fill=document.getElementById('sFill'),txt=document.getElementById('sTxt');
if(pwd){pwd.addEventListener('input',()=>{const v=pwd.value;let w='0%',c='#e0ddd5',t='';if(v.length>0){w='20%';c='#ef4444';t='Too short';}if(v.length>=8){w='50%';c='#f59e0b';t='Fair';}if(v.length>=8&&/[A-Z]/.test(v)&&/\d/.test(v)){w='80%';c='#84cc16';t='Good';}if(v.length>=10&&/[A-Z]/.test(v)&&/\d/.test(v)&&/[\W_]/.test(v)){w='100%';c='#22c55e';t='Strong';}fill.style.width=w;fill.style.background=c;txt.textContent=t;txt.style.color=c;});}
</script>
</body>
</html>
