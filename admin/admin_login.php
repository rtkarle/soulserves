<?php
// ── Handles both GET (render form) and POST (process login) ──
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

// POST: process login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = trim($_POST['email']    ?? '');
    $pass  = $_POST['password'] ?? '';   /* never trim passwords */

    if (!$email || !$pass) {
        header("Location: admin_login.php?error=1"); exit;
    }

    $admin = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM admins WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        error_log("Admin login DB error: " . $e->getMessage());
        header("Location: admin_login.php?error=1"); exit;
    }

    if ($admin && password_verify($pass, $admin['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id']    = $admin['id'];
        $_SESSION['admin_email'] = $admin['email'];
        header("Location: admin_dashboard.php");
    } else {
        header("Location: admin_login.php?error=1");
    }
    exit;
}

// GET: render the login form
$registered = isset($_GET['registered']);
$error      = isset($_GET['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Login | Adhaar</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ── Reset ─────────────────────────────────── */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}

/* ── Animated background ─────────────────── */
body{
  min-height:100vh;
  background:#0d0d0d;
  display:flex;align-items:center;justify-content:center;
  padding:20px;overflow:hidden;position:relative;
}

/* Canvas particle layer */
#bg-canvas{
  position:fixed;inset:0;z-index:0;pointer-events:none;
}

/* Gradient blobs */
.blob{
  position:fixed;border-radius:50%;filter:blur(90px);opacity:.18;
  animation:float 8s ease-in-out infinite;pointer-events:none;z-index:0;
}
.blob1{width:520px;height:520px;background:radial-gradient(circle,#006D77,transparent);top:-100px;left:-100px;animation-delay:0s}
.blob2{width:480px;height:480px;background:radial-gradient(circle,#2E8B57,transparent);bottom:-80px;right:-80px;animation-delay:-3s}
.blob3{width:300px;height:300px;background:radial-gradient(circle,#0F766E,transparent);top:40%;left:30%;animation-delay:-5s}

@keyframes float{
  0%,100%{transform:translate(0,0) scale(1)}
  33%{transform:translate(30px,-20px) scale(1.05)}
  66%{transform:translate(-20px,30px) scale(.97)}
}

/* ── Card ─────────────────────────────────── */
.card{
  position:relative;z-index:1;
  background:rgba(255,255,255,.04);
  backdrop-filter:blur(22px);
  -webkit-backdrop-filter:blur(22px);
  border:1px solid rgba(255,255,255,.09);
  width:100%;max-width:440px;
  padding:52px 44px;
  border-radius:28px;
  box-shadow:0 40px 100px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.03);
  animation:slideUp .6s cubic-bezier(.22,1,.36,1) both;
}

@keyframes slideUp{
  from{opacity:0;transform:translateY(40px) scale(.97)}
  to{opacity:1;transform:none}
}

/* ── Brand ─────────────────────────────────── */
.brand{text-align:center;margin-bottom:36px}
.brand-ring{
  width:68px;height:68px;border-radius:20px;
  background:linear-gradient(135deg,#006D77,#2E8B57);
  display:flex;align-items:center;justify-content:center;
  font-size:30px;margin:0 auto 16px;
  box-shadow:0 8px 28px rgba(0,109,119,.35);
  animation:pulse-ring 3s ease-in-out infinite;
}
@keyframes pulse-ring{
  0%,100%{box-shadow:0 8px 28px rgba(0,109,119,.35)}
  50%{box-shadow:0 8px 40px rgba(46,139,87,.55),0 0 0 10px rgba(0,109,119,.08)}
}
.brand h1{font-size:23px;font-weight:800;color:#fff;letter-spacing:-.3px}
.brand p{font-size:13px;color:rgba(255,255,255,.45);margin-top:4px}

/* ── Alerts ───────────────────────────────── */
.alert{
  padding:11px 16px;border-radius:12px;font-size:13px;
  margin-bottom:22px;text-align:center;display:flex;
  align-items:center;justify-content:center;gap:8px;
  animation:fadeIn .4s ease;
}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.alert-success{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
.alert-error  {background:rgba(239,68,68,.15); border:1px solid rgba(239,68,68,.3); color:#fca5a5}

/* ── Form fields ─────────────────────────── */
.field{margin-bottom:20px;position:relative}
.field label{
  display:block;font-size:11px;font-weight:700;
  color:rgba(255,255,255,.45);margin-bottom:7px;
  text-transform:uppercase;letter-spacing:.6px;
}
.field-wrap{position:relative}
.field-icon{
  position:absolute;left:15px;top:50%;transform:translateY(-50%);
  color:rgba(255,255,255,.3);font-size:15px;pointer-events:none;
}
.field input{
  width:100%;padding:13px 16px 13px 44px;
  border:1.5px solid rgba(255,255,255,.1);
  border-radius:13px;
  font-size:14px;color:#fff;
  background:rgba(255,255,255,.06);
  transition:.25s ease;outline:none;
  font-family:inherit;
}
.field input::placeholder{color:rgba(255,255,255,.22)}
.field input:focus{
  border-color:rgba(0,109,119,.8);
  background:rgba(255,255,255,.09);
  box-shadow:0 0 0 3px rgba(0,109,119,.15);
}
.field input:focus + .focus-bar{width:100%}

/* Animated focus underbar */
.focus-bar{
  position:absolute;bottom:-1px;left:50%;width:0;height:2px;
  background:linear-gradient(135deg,#006D77,#2E8B57);
  border-radius:2px;transform:translateX(-50%);
  transition:width .3s ease;
}

/* Show/hide password toggle */
.toggle-pass{
  position:absolute;right:14px;top:50%;transform:translateY(-50%);
  color:rgba(255,255,255,.35);cursor:pointer;font-size:15px;
  transition:.2s;user-select:none;
}
.toggle-pass:hover{color:rgba(255,255,255,.7)}

/* ── Submit button ───────────────────────── */
.btn{
  width:100%;padding:14px;border:none;
  border-radius:50px;
  background:linear-gradient(135deg,#006D77,#2E8B57);
  color:#fff;font-size:15px;font-weight:700;cursor:pointer;
  box-shadow:0 12px 30px rgba(0,109,119,.24);
  transition:.3s ease;margin-top:8px;
  display:flex;align-items:center;justify-content:center;gap:8px;
  position:relative;overflow:hidden;
  font-family:inherit;
}
.btn::after{
  content:'';position:absolute;inset:0;
  background:rgba(255,255,255,0);transition:.2s;
}
.btn:hover::after{background:rgba(255,255,255,.08)}
.btn:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(46,139,87,.28)}
.btn:active{transform:translateY(0)}

/* Ripple on click */
.btn .ripple{
  position:absolute;border-radius:50%;
  background:rgba(255,255,255,.25);
  animation:ripple .5s ease forwards;
  pointer-events:none;transform:scale(0);
}
@keyframes ripple{to{transform:scale(4);opacity:0}}

/* Loading spinner */
.spinner{
  display:none;width:18px;height:18px;
  border:2px solid rgba(255,255,255,.3);
  border-top-color:#fff;border-radius:50%;
  animation:spin .6s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
.btn.loading .spinner{display:block}
.btn.loading .btn-text{opacity:.7}

/* ── Divider ─────────────────────────────── */
.divider{
  display:flex;align-items:center;gap:12px;
  margin:22px 0 0;
  color:rgba(255,255,255,.25);font-size:12px;
}
.divider::before,.divider::after{
  content:'';flex:1;height:1px;
  background:rgba(255,255,255,.1);
}

/* ── Footer links ────────────────────────── */
.links{
  text-align:center;margin-top:22px;
  font-size:13px;color:rgba(255,255,255,.38);
}
.links a{
  color:#2E8B57;font-weight:600;text-decoration:none;
  transition:.2s;
}
.links a:hover{color:#bfb070}

/* ── Input shake animation on error ─────── */
@keyframes shake{
  0%,100%{transform:none}
  20%,60%{transform:translateX(-6px)}
  40%,80%{transform:translateX(6px)}
}
.shake{animation:shake .4s ease}
</style>
</head>
<body>

<!-- Canvas background -->
<canvas id="bg-canvas"></canvas>
<div class="blob blob1"></div>
<div class="blob blob2"></div>
<div class="blob blob3"></div>

<div class="card" id="loginCard">
  <div class="brand">
    <div class="brand-ring"><img src="../assets/logo.png" alt="SoulServe" style="width:64px;height:64px;object-fit:contain;border-radius:0;display:block;margin:0 auto"></div>
    <h1>Admin Login</h1>
    <p>SoulServe — Management Portal</p>
  </div>

  <?php if ($registered): ?>
  <div class="alert alert-success">
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    Account created! You can now log in.
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-error" id="errorAlert">
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    Invalid credentials. Please try again.
  </div>
  <?php endif; ?>

  <!-- CSRF token injected server-side — no fetch() race condition -->
  <form action="admin_login.php" method="POST" id="loginForm">
    <?= csrf_field() ?>

    <div class="field">
      <label>Email Address</label>
      <div class="field-wrap">
        <span class="field-icon">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 8l10 6 10-6"/></svg>
        </span>
        <input type="email" name="email" placeholder="admin@adhaar.org"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               required autocomplete="email" id="emailInput">
        <div class="focus-bar"></div>
      </div>
    </div>

    <div class="field">
      <label>Password</label>
      <div class="field-wrap">
        <span class="field-icon">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </span>
        <input type="password" name="password" placeholder="••••••••"
               required id="passInput">
        <span class="toggle-pass" id="togglePass" title="Show/hide password">
          <svg id="eyeIcon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
        </span>
        <div class="focus-bar"></div>
      </div>
    </div>

    <button class="btn" type="submit" id="loginBtn">
      <span class="btn-text">Sign In →</span>
      <div class="spinner" id="spinner"></div>
    </button>
  </form>

  <div class="divider">or</div>
  <div class="links">
    Don't have an account? <a href="admin_register.php">Create Admin Account</a>
  </div>
</div>

<script>
/* ── Particle canvas background ───────────────── */
(function(){
  const c = document.getElementById('bg-canvas');
  const ctx = c.getContext('2d');
  let W, H, pts = [];

  function init(){
    W = c.width  = window.innerWidth;
    H = c.height = window.innerHeight;
    pts = [];
    for(let i = 0; i < 70; i++){
      pts.push({
        x: Math.random()*W, y: Math.random()*H,
        vx: (Math.random()-.5)*.5,
        vy: (Math.random()-.5)*.5,
        r: Math.random()*1.8+.5
      });
    }
  }

  function draw(){
    ctx.clearRect(0,0,W,H);
    // Draw connections
    for(let i=0;i<pts.length;i++){
      for(let j=i+1;j<pts.length;j++){
        const dx=pts[i].x-pts[j].x, dy=pts[i].y-pts[j].y;
        const d=Math.sqrt(dx*dx+dy*dy);
        if(d<130){
          ctx.beginPath();
          ctx.moveTo(pts[i].x, pts[i].y);
          ctx.lineTo(pts[j].x, pts[j].y);
          ctx.strokeStyle=`rgba(154,143,92,${.18*(1-d/130)})`;
          ctx.lineWidth=.7;
          ctx.stroke();
        }
      }
    }
    // Draw dots
    pts.forEach(p=>{
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
      ctx.fillStyle='rgba(122,125,63,.6)';
      ctx.fill();
      p.x += p.vx; p.y += p.vy;
      if(p.x<0||p.x>W) p.vx*=-1;
      if(p.y<0||p.y>H) p.vy*=-1;
    });
    requestAnimationFrame(draw);
  }

  window.addEventListener('resize', init);
  init(); draw();
})();

/* ── Toggle password visibility ────────────────── */
const passInput  = document.getElementById('passInput');
const togglePass = document.getElementById('togglePass');
const eyeIcon    = document.getElementById('eyeIcon');

togglePass.addEventListener('click', ()=>{
  const isPass = passInput.type === 'password';
  passInput.type = isPass ? 'text' : 'password';
  eyeIcon.innerHTML = isPass
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>';
});

/* ── Loading state + ripple on submit ───────────── */
const loginForm = document.getElementById('loginForm');
const loginBtn  = document.getElementById('loginBtn');

loginBtn.addEventListener('click', function(e){
  // Ripple effect
  const r = document.createElement('span');
  r.className = 'ripple';
  const rect = this.getBoundingClientRect();
  const size = Math.max(rect.width, rect.height);
  r.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-rect.left-size/2}px;top:${e.clientY-rect.top-size/2}px`;
  this.appendChild(r);
  setTimeout(()=>r.remove(), 600);
});

loginForm.addEventListener('submit', function(){
  loginBtn.classList.add('loading');
  loginBtn.disabled = true;
});

/* ── Shake card on error ────────────────────────── */
<?php if ($error): ?>
const card = document.getElementById('loginCard');
card.style.animation = 'none';
requestAnimationFrame(()=>{
  card.style.animation = '';
  card.classList.add('shake');
});
setTimeout(()=>card.classList.remove('shake'), 500);
<?php endif; ?>
</script>
<script src="../js/script.js"></script>
</body>
</html>
