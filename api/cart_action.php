<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_email'])) {
    echo json_encode(['success'=>false,'needs_login'=>true,'message'=>'Please sign in to add items to cart']);
    exit;
}

$me     = $_SESSION['user_email'];
$action = $_POST['action'] ?? '';
$pid    = (int)($_POST['product_id'] ?? 0);
$qty    = max(1,(int)($_POST['quantity'] ?? 1));

if (!in_array($action,['add','update','remove'])) {
    echo json_encode(['success'=>false,'message'=>'Invalid action']); exit;
}

// Verify CSRF only for mutating ops
$submitted = trim($_POST['csrf_token'] ?? '');
if (!$submitted || !hash_equals(csrf_token(), $submitted)) {
    echo json_encode(['success'=>false,'message'=>'Invalid request']); exit;
}

if ($action === 'add') {
    // Check product exists and is active
    $pq = $conn->prepare("SELECT id, stock FROM products WHERE id=? AND is_active=1");
    $pq->bind_param("i",$pid); $pq->execute();
    $prod = $pq->get_result()->fetch_assoc();
    if (!$prod) { echo json_encode(['success'=>false,'message'=>'Product not available']); exit; }
    if ($prod['stock'] < $qty) { echo json_encode(['success'=>false,'message'=>'Not enough stock']); exit; }
    // Check if seller is trying to buy own product
    $sq = $conn->prepare("SELECT seller_email FROM products WHERE id=?");
    $sq->bind_param("i",$pid); $sq->execute();
    $sp = $sq->get_result()->fetch_assoc();
    if ($sp['seller_email'] === $me) { echo json_encode(['success'=>false,'message'=>'You cannot buy your own product']); exit; }

    $ins = $conn->prepare("INSERT INTO cart (user_email,product_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?");
    $ins->bind_param("siii",$me,$pid,$qty,$qty);
    $ins->execute();
}
elseif ($action === 'update') {
    /* ── Check stock before updating qty ── */
    $sq = $conn->prepare("SELECT stock FROM products WHERE id=? AND is_active=1");
    $sq->bind_param("i",$pid); $sq->execute();
    $prod = $sq->get_result()->fetch_assoc();
    if (!$prod) { echo json_encode(['success'=>false,'message'=>'Product not available']); exit; }
    if ($qty > $prod['stock']) { $qty = $prod['stock']; } /* cap at available stock */
    if ($qty < 1) { $qty = 1; }
    $upd = $conn->prepare("UPDATE cart SET quantity=? WHERE user_email=? AND product_id=?");
    $upd->bind_param("isi",$qty,$me,$pid);
    $upd->execute();
}
elseif ($action === 'remove') {
    $del = $conn->prepare("DELETE FROM cart WHERE user_email=? AND product_id=?");
    $del->bind_param("si",$me,$pid);
    $del->execute();
}

$cc_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM cart WHERE user_email=?");
$cc_stmt->bind_param("s", $me);
$cc_stmt->execute();
$cart_count = (int)$cc_stmt->get_result()->fetch_assoc()['c'];

echo json_encode(['success'=>true,'cart_count'=>$cart_count]);
