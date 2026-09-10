<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';

/* ── Optimized mysqli connection ── */
$mysqli_driver = new mysqli_driver();
$mysqli_driver->report_mode = MYSQLI_REPORT_OFF; // handle errors manually

$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn) {
    /* Reduce round-trips: set session vars in one query */
    mysqli_set_charset($conn, 'utf8mb4');
    $conn->query("SET time_zone='+05:30', sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
}

if (!$conn) {
    error_log("DB Error: " . mysqli_connect_error() . " [" . DB_HOST . ":" . DB_PORT . "]");
    // Return JSON error for API calls, HTML for page calls
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Database unavailable']);
    } else {
        http_response_code(500);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center">
        <h2>⚠️ Service Temporarily Unavailable</h2>
        <p>Database connection failed. Please try again in a moment.</p>
        </body></html>';
    }
    exit;
}

/* ── CSRF helpers ─────────────────────────────────────── */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void {
    $submitted = trim($_POST['csrf_token'] ?? '');
    if (!$submitted || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        die("Invalid request. Please go back and try again.");
    }
}
