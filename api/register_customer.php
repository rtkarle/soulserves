<?php
/**
 * Quick customer registration for shop guests.
 * Role is always 'donor' (buyer). No role selection needed.
 * POST: name, email, password, phone (optional)
 * Returns JSON: {ok, message, redirect}
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

header('Content-Type: application/json');

/* ── If already logged in, just return ok ── */
if (isset($_SESSION['user_email'])) {
    echo json_encode(['ok'=>true,'message'=>'Already signed in','redirect'=>'shop.php']);
    exit;
}

$name     = trim($_POST['name']     ?? '');
$email    = trim($_POST['email']    ?? '');
$password = trim($_POST['password'] ?? '');
$phone    = trim($_POST['phone']    ?? '');

/* ── Validation ── */
if (!$name || !$email || !$password) {
    echo json_encode(['ok'=>false,'message'=>'Please fill in all required fields.']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false,'message'=>'Please enter a valid email address.']); exit;
}
if (strlen($password) < 6) {
    echo json_encode(['ok'=>false,'message'=>'Password must be at least 6 characters.']); exit;
}

/* ── Check duplicate ── */
$chk = $conn->prepare("SELECT id, verified, role FROM register WHERE email=?");
$chk->bind_param("s", $email); $chk->execute();
$existing = $chk->get_result()->fetch_assoc();

if ($existing) {
    /* User exists — try to auto-login if password matches */
    $pw_chk = $conn->prepare("SELECT id, name, role, password FROM register WHERE email=? AND verified=1");
    $pw_chk->bind_param("s", $email); $pw_chk->execute();
    $user = $pw_chk->get_result()->fetch_assoc();
    if ($user && password_verify($password, $user['password'])) {
        /* Password correct — log them in */
        session_regenerate_id(true);
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['role']       = $user['role'];
        echo json_encode(['ok'=>true,'message'=>'Welcome back, '.htmlspecialchars($user['name']).'!','redirect'=>'shop.php','logged_in'=>true]);
    } else {
        echo json_encode(['ok'=>false,'message'=>'This email is already registered. Please use a different email or sign in.']);
    }
    exit;
}

/* ── Register new customer ── */
$hashed = password_hash($password, PASSWORD_DEFAULT);

// Customers are auto-verified — no OTP needed for shop buyers
$ins = $conn->prepare(
    "INSERT INTO register (name, email, mobile, password, role, verified, created_at)
     VALUES (?, ?, ?, ?, 'donor', 1, NOW())"
);
$ins->bind_param("ssss", $name, $email, $phone, $hashed);

if (!$ins->execute()) {
    echo json_encode(['ok'=>false,'message'=>'Registration failed. Please try again.']); exit;
}

/* ── Auto login after registration ── */
session_regenerate_id(true);
$_SESSION['user_email'] = $email;
$_SESSION['user_name']  = $name;
$_SESSION['role']       = 'donor';

/* ── Send welcome email (non-blocking) ── */
try {
    sendWelcomeMail($email, $name, 'donor');
} catch (Throwable $e) { /* silent */ }

echo json_encode([
    'ok'       => true,
    'message'  => "Welcome, $name! You're now registered and signed in.",
    'redirect' => 'shop.php',
    'logged_in'=> true,
]);
