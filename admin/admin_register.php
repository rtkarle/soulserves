<?php
require_once __DIR__ . '/../config/db.php';

/* ── Auto-create admins table if missing (Render fresh deploy) ── */
try {
    $conn->query("CREATE TABLE IF NOT EXISTS admins (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(120) NOT NULL,
        email      VARCHAR(180) NOT NULL UNIQUE,
        password   VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* non-fatal */ }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name   = trim($_POST['name']       ?? '');
    $email  = trim($_POST['email']      ?? '');
    $pass   = $_POST['password']        ?? '';   /* never trim passwords */
    $conf   = $_POST['confirm']         ?? '';
    $secret = trim($_POST['secret_key'] ?? '');

    if ($secret !== 'ADHAAR_ADMIN_2026') {
        $error = 'Invalid admin secret key.';
    } elseif (!$name || !$email || !$pass) {
        $error = 'All fields are required.';
    } elseif ($pass !== $conf) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $chk = $conn->prepare("SELECT id FROM admins WHERE email=?");
            $chk->bind_param("s", $email); $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $error = 'An admin with this email already exists.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $ins  = $conn->prepare("INSERT INTO admins(name,email,password,created_at) VALUES(?,?,?,NOW())");
                $ins->bind_param("sss", $name, $email, $hash);
                $ins->execute();
                header("Location: admin_login.php?registered=1"); exit;
            }
        } catch (Throwable $e) {
            error_log("Admin register DB error: " . $e->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Admin Account | Adhaar</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#7a7d3f;--accent2:#9a8f5c;--text:#2f2e26;--muted:#5a594d}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#1a1917,#2f2e26,#3d3c30);display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:#fff;width:100%;max-width:460px;padding:48px 44px;border-radius:28px;box-shadow:0 40px 100px rgba(0,0,0,.45);animation:fadeUp .5s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
.brand{text-align:center;margin-bottom:32px}
.brand-icon{width:60px;height:60px;border-radius:16px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;box-shadow:0 8px 24px rgba(122,125,63,.4)}
.brand h1{font-size:22px;font-weight:800;color:var(--text)}
.brand p{font-size:13px;color:var(--muted);margin-top:5px}
.field{margin-bottom:18px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px}
.field input{width:100%;padding:13px 16px;border:2px solid #e5e3d8;border-radius:12px;font-size:14px;color:var(--text);background:#fafaf6;transition:.25s;outline:none;font-family:inherit}
.field input:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(122,125,63,.1)}
.secret-info{background:#fef3c7;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:18px;font-weight:600}
.strength-bar{height:4px;background:#e0ddd5;border-radius:4px;margin-top:6px;overflow:hidden}
.strength-fill{height:100%;border-radius:4px;width:0%;transition:.3s}
.strength-txt{font-size:11px;color:var(--muted);margin-top:4px;font-weight:600}
.btn{width:100%;padding:14px;border:none;border-radius:50px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 12px 30px rgba(122,125,63,.4);transition:.3s;margin-top:4px}
.btn:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(122,125,63,.55)}
.error-box{background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:18px;text-align:center}
.switch{text-align:center;margin-top:20px;font-size:13px;color:var(--muted)}
.switch a{color:var(--accent);font-weight:700;text-decoration:none}
.switch a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-icon">🛡️</div>
    <h1>Create Admin Account</h1>
    <p>Adhaar – The SoulServe Admin Panel</p>
  </div>

  <?php if ($error): ?>
    <div class="error-box">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="secret-info">🔑 Admin secret key required. Contact your system administrator.</div>

  <form method="POST">
    <?= csrf_field() ?>
    <div class="field">
      <label>Full Name</label>
      <input type="text" name="name" placeholder="Admin full name" required autocomplete="name">
    </div>
    <div class="field">
      <label>Email Address</label>
      <input type="email" name="email" placeholder="admin@adhaar.org" required autocomplete="email">
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" id="pwdInput" placeholder="Minimum 8 characters" required>
      <div class="strength-bar"><div class="strength-fill" id="sFill"></div></div>
      <div class="strength-txt" id="sTxt"></div>
    </div>
    <div class="field">
      <label>Confirm Password</label>
      <input type="password" name="confirm" placeholder="Repeat password" required>
    </div>
    <div class="field">
      <label>Admin Secret Key</label>
      <input type="password" name="secret_key" placeholder="Enter admin secret key" required>
    </div>
    <button class="btn" type="submit">Create Admin Account →</button>
  </form>

  <p class="switch">Already have an account? <a href="admin_login.php">Login</a></p>
</div>
<script>
const p=document.getElementById('pwdInput'),f=document.getElementById('sFill'),t=document.getElementById('sTxt');
p.addEventListener('input',()=>{const v=p.value;let w='0%',c='#e0ddd5',l='';
if(v.length>0){w='20%';c='#ef4444';l='Very Weak';}
if(v.length>=8){w='50%';c='#f59e0b';l='Fair';}
if(v.length>=8&&/[A-Z]/.test(v)&&/\d/.test(v)){w='80%';c='#84cc16';l='Good';}
if(v.length>=10&&/[A-Z]/.test(v)&&/\d/.test(v)&&/[\W_]/.test(v)){w='100%';c='#22c55e';l='Strong';}
f.style.width=w;f.style.background=c;t.textContent=l;t.style.color=c;});
</script>
</body>
</html>
