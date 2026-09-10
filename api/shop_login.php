<?php
/**
 * SoulServe — Shop AJAX Login (Customer/Buyer login from shop modal)
 * Returns JSON {ok, message, role}
 */
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'message'=>'Invalid request']); exit;
}

$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';   // never trim passwords

if (!$email || !$pass) {
    echo json_encode(['ok'=>false,'message'=>'Email and password are required.']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false,'message'=>'Invalid email address.']); exit;
}

/* ── Rate limit: 5 attempts per 5 min ── */
$window = date('Y-m-d H:i:s', time() - 300);
try {
    $tbl = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($tbl && $tbl->num_rows > 0) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $attempts = (int)$conn->query(
            "SELECT COUNT(*) c FROM login_attempts WHERE email='" .
            mysqli_real_escape_string($conn,$email) . "' AND attempted_at > '$window'"
        )->fetch_assoc()['c'];
        if ($attempts >= 5) {
            echo json_encode(['ok'=>false,'message'=>'Too many attempts. Please wait 5 minutes.']); exit;
        }
    }
} catch (Throwable $e) {}

/* ── Lookup user ── */
$stmt = $conn->prepare("SELECT * FROM register WHERE email=? AND verified=1");
$stmt->bind_param("s", $email); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($pass, $user['password'])) {
    /* ── Clean login attempts ── */
    try { $conn->query("DELETE FROM login_attempts WHERE email='" . mysqli_real_escape_string($conn,$email) . "'"); } catch(Throwable $e){}

    session_regenerate_id(true);
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['role']       = $user['role'];

    echo json_encode([
        'ok'      => true,
        'message' => 'Signed in as ' . htmlspecialchars($user['name']) . '! Refreshing…',
        'role'    => $user['role'],
        'name'    => $user['name'],
    ]);
} else {
    /* ── Log failed attempt ── */
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ins = $conn->prepare("INSERT INTO login_attempts (email,ip,attempted_at) VALUES (?,?,NOW())");
        $ins->bind_param("ss",$email,$ip); $ins->execute();
    } catch(Throwable $e){}

    echo json_encode(['ok'=>false,'message'=>'Incorrect email or password. Try again.']);
}
