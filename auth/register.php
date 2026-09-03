<?php
header('Content-Type: text/html; charset=utf-8');
/* ── All backend logic preserved exactly ── */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

$error = "";

if (isset($_POST['send_otp'])) {
  csrf_verify();
  if ($_POST['password'] != $_POST['confirm']) {
    $error = "Passwords do not match.";
  } else {
    $email = trim($_POST['email']);
    $role  = in_array($_POST['role'], ['donor','volunteer','seller']) ? $_POST['role'] : 'donor';
    $check = $conn->prepare("SELECT id FROM register WHERE email=?");
    $check->bind_param("s", $email); $check->execute();
    if ($check->get_result()->num_rows > 0) {
      $error = "Email already registered.";
    } else {
      $_SESSION['regdata'] = [
        "name"             => trim($_POST['name']),
        "email"            => $email,
        "mobile"           => trim($_POST['mobile']),
        "password"         => password_hash($_POST['password'], PASSWORD_DEFAULT),
        "role"             => $role,
        "volunteer_reason" => trim($_POST['volunteer_reason'] ?? ''),
      ];
      $otp = rand(100000, 999999);
      $del = $conn->prepare("DELETE FROM otps WHERE email=?");
      $del->bind_param("s", $email); $del->execute();
      $stmt = $conn->prepare("INSERT INTO otps(email,otp,created_at) VALUES(?,?,NOW())");
      $stmt->bind_param("ss", $email, $otp); $stmt->execute();
      $_SESSION['regdata']['otp'] = $otp;
      sendOTPMail($email, $otp);
      header("Location: verify_otp.php"); exit;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Account — SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
html,body{height:100%}
.auth-page{min-height:100vh}
.pwd-strength{height:4px;border-radius:4px;background:var(--border);margin-top:6px;overflow:hidden}
.pwd-bar{height:100%;border-radius:4px;transition:width .3s,background .3s;width:0}
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
    <h2 class="auth-brand-headline">Join the<br>Movement.</h2>
    <p class="auth-brand-sub">Thousands of donors, volunteers and artisans are already creating infinite impact. Your turn.</p>
    <div class="auth-brand-pillars">
      <div class="auth-pillar"><div class="auth-pillar-icon">&#127917;</div><div class="auth-pillar-text">Donor — Donate surplus food &amp; clothing</div></div>
      <div class="auth-pillar"><div class="auth-pillar-icon">&#129309;</div><div class="auth-pillar-text">Volunteer — Help with pickups &amp; deliveries</div></div>
      <div class="auth-pillar"><div class="auth-pillar-icon">&#127881;</div><div class="auth-pillar-text">Seller — Sell handmade products to buyers</div></div>
    </div>
  </div>

  <!-- Right form panel -->
  <div class="auth-form-side">
    <div class="auth-form-wrap">
      <h2>Create your account</h2>
      <p class="auth-sub">Already have one? <a href="login.php">Sign in here</a></p>

      <?php if($error): ?>
      <div style="background:#fee2e2;border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:14px;font-weight:600;color:#991b1b;display:flex;align-items:center;gap:8px">
        &#9888; <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- Google OAuth -->
      <a href="google_login.php" class="google-btn">
        <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        Continue with Google
      </a>

      <div class="divider">or register with email</div>

      <form method="POST" action="register.php">
        <?= csrf_field() ?>

        <!-- Role selector -->
        <div class="form-group">
          <label class="form-label">I want to join as</label>
          <div class="role-cards">
            <label class="role-card" id="rc-donor">
              <input type="radio" name="role" value="donor" id="r-donor" checked style="display:none">
              <div class="rc-icon">&#127917;</div>
              <div class="rc-label">Donor</div>
            </label>
            <label class="role-card" id="rc-volunteer">
              <input type="radio" name="role" value="volunteer" id="r-volunteer" style="display:none">
              <div class="rc-icon">&#129309;</div>
              <div class="rc-label">Volunteer</div>
            </label>
            <label class="role-card" id="rc-seller">
              <input type="radio" name="role" value="seller" id="r-seller" style="display:none">
              <div class="rc-icon">&#127881;</div>
              <div class="rc-label">Seller</div>
            </label>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input class="form-input" type="text" name="name" placeholder="Your full name" required autocomplete="name">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-input" type="email" name="email" placeholder="you@email.com" required autocomplete="email">
          </div>
          <div class="form-group">
            <label class="form-label">Mobile</label>
            <input class="form-input" type="tel" name="mobile" placeholder="+91 98765..." required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <input class="form-input" type="password" name="password" id="pwd" placeholder="Min. 8 characters" required autocomplete="new-password" oninput="checkStrength(this.value)">
          <div class="pwd-strength"><div class="pwd-bar" id="pwdBar"></div></div>
          <div id="pwdHint" style="font-size:11px;color:var(--text-muted);margin-top:4px"></div>
        </div>

        <div class="form-group">
          <label class="form-label">Confirm Password</label>
          <input class="form-input" type="password" name="confirm" placeholder="Repeat password" required autocomplete="new-password">
        </div>

        <!-- Volunteer reason (shown conditionally) -->
        <div class="form-group" id="volReasonGroup" style="display:none">
          <label class="form-label">Why do you want to volunteer?</label>
          <textarea class="form-textarea" name="volunteer_reason" rows="2" placeholder="Tell us about your motivation..."></textarea>
        </div>

        <!-- Seller info (shown conditionally) -->
        <div class="form-group" id="sellerGroup" style="display:none">
          <label class="form-label">What products will you sell?</label>
          <textarea class="form-textarea" name="volunteer_reason" rows="2" placeholder="e.g. Handloom sarees, organic spices..."></textarea>
        </div>

        <button type="submit" name="send_otp" class="btn btn-primary w-full" style="margin-top:8px;justify-content:center">
          Continue &#8594; Send OTP
        </button>
      </form>

      <p style="text-align:center;margin-top:20px;font-size:12px;color:var(--text-light);line-height:1.6">
        By creating an account you agree to our Terms of Service and Privacy Policy.
      </p>
    </div>
  </div>
</div>

<script>
// Role card selection
document.querySelectorAll('.role-card').forEach(card=>{
  card.addEventListener('click',()=>{
    document.querySelectorAll('.role-card').forEach(c=>c.classList.remove('selected'));
    card.classList.add('selected');
    const radio = card.querySelector('input[type=radio]');
    if(radio) radio.checked=true;
    const role = radio?.value;
    document.getElementById('volReasonGroup').style.display = role==='volunteer'?'block':'none';
    document.getElementById('sellerGroup').style.display    = role==='seller'?'block':'none';
  });
});
// Mark donor selected by default
document.getElementById('rc-donor')?.classList.add('selected');

// Password strength
function checkStrength(v){
  const bar=document.getElementById('pwdBar');
  const hint=document.getElementById('pwdHint');
  if(!bar)return;
  let score=0;
  if(v.length>=8)score++;
  if(/[A-Z]/.test(v))score++;
  if(/[0-9]/.test(v))score++;
  if(/[^A-Za-z0-9]/.test(v))score++;
  const w=['0%','30%','55%','80%','100%'];
  const c=['','#ef4444','#f59e0b','#3b82f6','#10b981'];
  const l=['','Weak','Fair','Good','Strong'];
  bar.style.width=w[score];bar.style.background=c[score];
  hint.textContent=l[score];hint.style.color=c[score];
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
