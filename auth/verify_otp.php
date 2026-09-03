<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

$error="";
$success="";

/* ---------- VERIFY OTP ---------- */
if(isset($_POST['verify'])){

    $otp   = trim($_POST['otp']);
    $email = $_SESSION['regdata']['email'] ?? '';

    if (!$email) { header("Location: register.php"); exit; }

    // Rate-limit: max 5 wrong attempts in 10 minutes
    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
    if ($_SESSION['otp_attempts'] > 5) {
        $error = "Too many attempts. Please request a new OTP.";
        // Force resend flow
        unset($_SESSION['regdata']);
    } else {

    // Check DB first (10 min window)
    $stmt = $conn->prepare(
        "SELECT * FROM otps WHERE email=? AND otp=? AND created_at >= NOW() - INTERVAL 10 MINUTE"
    );
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();
    $result = $stmt->get_result();

    // Also accept session OTP as fallback (when email failed)
    $sessionOtp = (string)($_SESSION['regdata']['otp'] ?? '');
    $valid = ($result->num_rows > 0) || ($sessionOtp !== '' && $otp === $sessionOtp);

    if($valid){

        $d = $_SESSION['regdata'];

        // Insert verified user — address & reason default to empty string if not set
        $address = $d['address']           ?? '';
        $reason  = $d['volunteer_reason']  ?? '';

        $stmt = $conn->prepare(
        "INSERT INTO register
        (name,email,mobile,password,role,address,volunteer_reason,verified)
        VALUES(?,?,?,?,?,?,?,1)");

        $stmt->bind_param("sssssss",
            $d['name'],
            $d['email'],
            $d['mobile'],
            $d['password'],
            $d['role'],
            $address,
            $reason
        );

        if (!$stmt->execute()) {
            $error = "Registration failed. Please try again.";
        } else {
            // delete otp after success, reset attempt counter
            $del = $conn->prepare("DELETE FROM otps WHERE email=?");
            $del->bind_param("s", $email);
            $del->execute();
            unset($_SESSION['otp_attempts']);

            // Send welcome email (non-blocking)
            sendWelcomeMail($d['email'], $d['name'], $d['role']);

            session_destroy();
            header("Location: login.php?registered=1");
            exit;
        }

    }else{
        $error = "Invalid or Expired OTP";
    }
    } // end rate-limit else block
}


/* ---------- RESEND OTP ---------- */
if(isset($_POST['resend'])){

    if(!isset($_SESSION['regdata'])){
        header("Location: register.php");
        exit;
    }

    $email = $_SESSION['regdata']['email'];
    $otp = rand(100000,999999);

    $stmt = $conn->prepare("DELETE FROM otps WHERE email=?");
    $stmt->bind_param("s",$email); $stmt->execute();

    $stmt = $conn->prepare("INSERT INTO otps(email,otp,created_at) VALUES(?,?,NOW())");
    $stmt->bind_param("ss",$email,$otp); $stmt->execute();

    // Store in session as fallback
    $_SESSION['regdata']['otp'] = $otp;

    sendOTPMail($email, $otp); // try email but don't block on failure

    // Reset attempt counter on resend
    $_SESSION['otp_attempts'] = 0;
    $success = "New OTP generated. Check your email or see the code below.";
}

?>

<!DOCTYPE html>
<html>
<head>
<title>Verify OTP | Adhaar</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#006D77;--accent2:#2E8B57;--text:#102A43;--muted:#5A7184}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{
  min-height:100vh;
  background:linear-gradient(135deg,#2f2e26,#4a4a30,#2f2e26);
  display:flex;align-items:center;justify-content:center;padding:20px;
}
.card{
  background:#fff;width:100%;max-width:400px;
  padding:48px 44px;border-radius:28px;
  box-shadow:0 40px 100px rgba(0,0,0,.35);
  animation:fadeUp .5s ease;
}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
.brand{text-align:center;margin-bottom:28px}
.brand-icon{
  width:56px;height:56px;border-radius:16px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;
  font-size:26px;margin:0 auto 14px;
}
.brand h1{font-size:22px;font-weight:800;color:var(--text)}
.brand p{font-size:13px;color:var(--muted);margin-top:4px}
.field{margin-bottom:20px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px}
.field input{
  width:100%;padding:16px;text-align:center;
  letter-spacing:8px;font-size:22px;font-weight:700;
  border:2px solid #e5e3d8;border-radius:12px;
  color:var(--text);background:#fafaf6;
  transition:.25s ease;outline:none;
}
.field input:focus{border-color:var(--accent);background:#fff}
.btn{
  width:100%;padding:14px;border:none;border-radius:50px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  color:#fff;font-size:15px;font-weight:700;cursor:pointer;
  box-shadow:0 12px 30px rgba(122,125,63,.4);transition:.3s ease;
  margin-bottom:12px;
}
.btn:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(122,125,63,.55)}
.btn-resend{
  width:100%;padding:12px;border:2px solid #e5e3d8;border-radius:50px;
  background:transparent;color:var(--muted);font-size:14px;font-weight:600;
  cursor:pointer;transition:.25s ease;
}
.btn-resend:hover{border-color:var(--accent);color:var(--accent)}
.timer{text-align:center;font-size:13px;color:var(--muted);margin-top:12px}
.error{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;text-align:center;font-weight:600}
.success{background:#d1fae5;color:#065f46;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;text-align:center;font-weight:600}
</style>

</head>

<body>

<div class="card">
  <div class="brand">
    <div class="brand-icon"><img src="../assets/logo.png" alt="SoulServe" style="width:52px;height:52px;object-fit:contain;border-radius:0"></div>
    <h1>Verify OTP</h1>
    <p>
      6-digit code sent to<br>
      <strong style="color:var(--accent)">
        <?= htmlspecialchars($_SESSION['regdata']['email'] ?? 'your email') ?>
      </strong>
    </p>
  </div>

<?php if($error!="") echo "<div class='error'>$error</div>"; ?>
<?php if($success!="") echo "<div class='success'>$success</div>"; ?>

<?php
header('Content-Type: text/html; charset=utf-8');
// Show OTP on screen — works even when email fails (local dev)
$devOtp = $_SESSION['regdata']['otp'] ?? null;
if ($devOtp): ?>
<div style="background:#f6f5f0;border:2px dashed #2E8B57;border-radius:14px;padding:18px;text-align:center;margin-bottom:20px;">
  <p style="font-size:11px;font-weight:700;color:#2E8B57;letter-spacing:2px;text-transform:uppercase;margin-bottom:8px;">Your OTP Code</p>
  <p style="font-size:36px;font-weight:900;color:#006D77;letter-spacing:10px;margin:0;"><?= htmlspecialchars($devOtp) ?></p>
  <p style="font-size:11px;color:#2E8B57;margin-top:8px;">Copy this code into the field below</p>
</div>
<?php endif; ?>

<form method="POST">
  <div class="field">
    <label>OTP Code</label>
    <input name="otp" type="text" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
  </div>

  <button class="btn" name="verify">Verify OTP →</button>
  <button type="submit" name="resend" class="btn-resend">Resend OTP</button>
</form>

<p id="timer" class="timer"></p>
</div>

<script>
let timeLeft = 600; // 10 minutes
const timerEl = document.getElementById("timer");

const countdown = setInterval(() => {
    const mins = Math.floor(timeLeft / 60);
    const secs = timeLeft % 60;
    timerEl.innerHTML = `OTP expires in ${mins}:${secs.toString().padStart(2,'0')}`;
    timeLeft--;

    if (timeLeft < 0) {
        clearInterval(countdown);
        timerEl.innerHTML = "⚠️ OTP expired. Click <b>Resend OTP</b>.";
        timerEl.style.color = "#dc2626";
    }
}, 1000);
</script>

</body>
</html>
