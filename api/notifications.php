<?php
/**
 * SoulServe Notifications API
 * Fetches user notifications and handles mark-as-read actions.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_email'])) {
    echo json_encode(['success' => false, 'count' => 0, 'notifications' => []]);
    exit;
}

$user_email = $_SESSION['user_email'];

// Ensure notifications table exists
try {
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(180) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'info',
        link VARCHAR(255) DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_unread (user_email, is_read),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// POST: Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all') {
        $up = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_email=?");
        $up->bind_param("s", $user_email);
        $up->execute();
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'mark_read') {
        $nid = (int)($_POST['id'] ?? 0);
        $up = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_email=?");
        $up->bind_param("is", $nid, $user_email);
        $up->execute();
        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: Fetch recent notifications + unread count
$unread_count = 0;
$items = [];
try {
    $cq = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_email=? AND is_read=0");
    $cq->bind_param("s", $user_email);
    $cq->execute();
    $unread_count = (int)($cq->get_result()->fetch_assoc()['c'] ?? 0);

    $lq = $conn->prepare("SELECT id, title, message, type, link, is_read, created_at 
                          FROM notifications 
                          WHERE user_email=? 
                          ORDER BY created_at DESC LIMIT 25");
    $lq->bind_param("s", $user_email);
    $lq->execute();
    $items = $lq->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($items as &$it) {
        $ts = strtotime($it['created_at']);
        $diff = time() - $ts;
        if ($diff < 60) $it['time_ago'] = 'Just now';
        elseif ($diff < 3600) $it['time_ago'] = floor($diff/60) . 'm ago';
        elseif ($diff < 86400) $it['time_ago'] = floor($diff/3600) . 'h ago';
        else $it['time_ago'] = date('d M', $ts);
    }
    unset($it);

} catch (Throwable $e) {
    error_log("[notifications] Error: " . $e->getMessage());
}

echo json_encode([
    'success'       => true,
    'unread_count'  => $unread_count,
    'notifications' => $items
]);
