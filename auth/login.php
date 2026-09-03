<?php
header('Content-Type: text/html; charset=utf-8');
/* ── POST handler — all backend logic preserved exactly ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/../config/db.php';
    csrf_verify();

    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';   /* NEVER trim passwords — spaces are valid */
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!$email || !$pass) { header("Location: login.php?error=1"); exit; }

    $window  = date('Y-m-d H:i:s', time() - 300);
    $chk_tbl = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($chk_tbl && $chk_tbl->num_rows > 0) {
        $attempts = (int)$conn->query(
            "SELECT COUNT(*) c FROM login_attempts WHERE email='".mysqli_real_escape_string($conn,$email)."' AND attempted_at > '$window'"
        )->fetch_assoc()['c'];
        if ($attempts >= 5) {
            $ins = $conn->prepare("INSERT INTO login_attempts (email,ip,attempted_at) VALUES (?,?,NOW())");
            $ins->bind_param("ss",$email,$ip); $ins->execute();
            header("Location: login.php?error=1&locked=1"); exit;
        }
    }

    $stmt = $conn->prepare("SELECT * FROM register WHERE email=? AND verified=1");
    $stmt->bind_param("s", $email); $stmt->execute();
    $res  = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        if (password_verify($pass, $user['password'])) {
            $chk_tbl2 = $conn->query("SHOW TABLES LIKE 'login_attempts'");
            if ($chk_tbl2 && $chk_tbl2->num_rows > 0)
                $conn->query("DELETE FROM login_attempts WHERE email='".mysqli_real_escape_string($conn,$email)."'");
            session_regenerate_id(true);
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['role']       = $user['role'];
            switch ($user['role']) {
                case 'volunteer': header("Location: ../volunteer/volunteer_dashboard.php"); break;
                case 'seller':    header("Location: ../seller/seller_dashboard.php");      break;
                default:          header("Location: ../donor/donor_dashboard.php");
            }
            exit;
        }
    }
    $chk_tbl3 = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($chk_tbl3 && $chk_tbl3->num_rows > 0) {
        $ins2 = $conn->prepare("INSERT INTO login_attempts (email,ip,attempted_at) VALUES (?,?,NOW())");
        $ins2->bind_param("ss",$email,$ip); $ins2->execute();
    }
    header("Location: login.php?error=1"); exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
$error      = isset($_GET['error']);
$locked     = isset($_GET['locked']);
$registered = isset($_GET['registered']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login — SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
html,body{height:100%}
.auth-page{min-height:100vh}
</style>
</head>
<body>
<div class="auth-page">

  <!-- Left brand panel -->
  <div class="auth-brand">
    <div class="auth-brand-logo">
      <img src="../assets/logo.png" alt="SoulServe" style="width:90px;object-fit:contain;display:block;margin:0 auto">
      <div style="font-size:18px;font-weight:900;color:#fff;margin-top:10px;text-align:center">SoulServe<span style="display:block;font-size:11px;font-weight:500;color:rgba(255,255,255,.6);margin-top:2px;letter-spacing:.5px">Endless Service. Infinite Impact.</span></div>
    </div>
    <h2 class="auth-brand-headline">Welcome<br>Back.</h2>
    <p class="auth-brand-sub">Every login is a step toward more impact. Donate, volunteer, track — all in one place.</p>
    <div class="auth-brand-pillars">
      <div class="auth-pillar"><div class="auth-pillar-icon">&#127869;</div><div class="auth-pillar-text">Donate food &amp; clothing to families in need</div></div>
      <div class="auth-pillar"><div class="auth-pillar-icon">&#129309;</div><div class="auth-pillar-text">Volunteer for pickups in your neighbourhood</div></div>
      <div class="auth-pillar"><div class="auth-pillar-icon">&#127881;</div><div class="auth-pillar-text">Shop from rural artisans and support livelihoods</div></div>
    </div>
  </div>

  <!-- Right form panel -->
  <div class="auth-form-side">
    <div class="auth-form-wrap">
      <h2>Sign in to SoulServe</h2>
      <p class="auth-sub">New here? <a href="register.php">Create a free account</a></p>

      <?php if($registered): ?>
      <div style="background:#d1fae5;border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:14px;font-weight:600;color:#065f46;display:flex;align-items:center;gap:8px">
        &#10003; Account created! Please sign in.
      </div>
      <?php endif; ?>

      <?php if($error): ?>
      <div style="background:#fee2e2;border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:14px;font-weight:600;color:#991b1b;display:flex;align-items:center;gap:8px">
        &#9888;
        <?php echo $locked ? 'Too many attempts. Please wait 5 minutes.' : 'Invalid email or password. Please try again.'; ?>
      </div>
      <?php endif; ?>

      <!-- Google OAuth -->
      <a href="google_login.php" class="google-btn">
        <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        Continue with Google
      </a>

      <div class="divider">or sign in with email</div>

      <form method="POST" action="login.php">
        <?= csrf_field() ?>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input class="form-input" type="email" name="email" placeholder="you@email.com" required autocomplete="email">
        </div>
        <div class="form-group">
          <label class="form-label" style="display:flex;justify-content:space-between;align-items:center">
            Password
            <a href="forgot.php" style="font-size:12px;color:var(--teal);font-weight:600;text-transform:none;letter-spacing:0">Forgot password?</a>
          </label>
          <div style="position:relative">
            <input class="form-input" type="password" name="password" id="pwdField" placeholder="Your password" required autocomplete="current-password" style="padding-right:48px">
            <button type="button" onclick="togglePwd()" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:18px;background:none;border:none;cursor:pointer" id="eyeBtn">&#128065;</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-full" style="margin-top:8px;justify-content:center">
          Sign In &#8594;
        </button>
      </form>

      <p style="text-align:center;margin-top:24px;font-size:13px;color:var(--text-muted)">
        Don't have an account? <a href="register.php" style="color:var(--teal);font-weight:700">Create one free</a>
      </p>

      <div style="margin-top:32px;padding-top:24px;border-top:1px solid var(--border);display:flex;gap:16px;justify-content:center">
        <a href="../admin/admin_login.php" style="font-size:12px;color:var(--text-muted);font-weight:600;transition:.2s" onmouseover="this.style.color='var(--teal)'" onmouseout="this.style.color='var(--text-muted)'">&#128737; Admin Login</a>
      </div>
    </div>
  </div>
</div>

<script>
function togglePwd(){
  const f=document.getElementById('pwdField'),b=document.getElementById('eyeBtn');
  if(f.type==='password'){f.type='text';b.textContent='🙈';}
  else{f.type='password';b.innerHTML='&#128065;';}
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
