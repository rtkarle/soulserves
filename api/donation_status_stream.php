<?php
/**
 * SoulServe — Server-Sent Events (SSE) Donation Status Stream
 *
 * Streams real-time donation status updates to the donor.
 * The client connects once; we push events whenever status changes.
 *
 * Usage: EventSource('/api/donation_status_stream.php')
 *
 * Events emitted:
 *   type: "status"   data: JSON { donations: [...] }
 *   type: "ping"     data: timestamp (keepalive every 20s)
 *   type: "error"    data: message
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

/* ── Auth guard ── */
if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$email = $_SESSION['user_email'];

/* ── SSE headers ── */
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');   // disable nginx output buffering
header('Connection: keep-alive');

/* Disable PHP output buffering completely */
if (ob_get_level()) ob_end_clean();
set_time_limit(0);
ignore_user_abort(false);

/* ── Helper: emit an SSE event ── */
function sse_emit(string $event, mixed $data): void {
    $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
    echo "event: {$event}\n";
    echo "data: {$payload}\n\n";
    flush();
}

/* ── Helper: fetch current donation statuses ── */
function fetch_statuses(mysqli $conn, string $email): array {
    $me = mysqli_real_escape_string($conn, $email);

    $food = $conn->query(
        "SELECT COALESCE(donation_id,CONCAT('DON-FOOD-',LPAD(id,6,'0'))) AS don_id,
                'food' AS type, id, quantity, status, pickup_address,
                pickup_date, pickup_time, volunteer_email, created_at
         FROM food_donations
         WHERE donor_email='$me'
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $cloth = $conn->query(
        "SELECT COALESCE(donation_id,CONCAT('DON-CLO-',LPAD(id,6,'0'))) AS don_id,
                'cloth' AS type, id, quantity, status, pickup_address,
                pickup_date, pickup_time, volunteer_email, created_at
         FROM cloth_donations
         WHERE donor_email='$me'
         ORDER BY created_at DESC
         LIMIT 20"
    );

    $rows = [];
    if ($food)  while ($r = $food->fetch_assoc())  $rows[] = $r;
    if ($cloth) while ($r = $cloth->fetch_assoc()) $rows[] = $r;

    usort($rows, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    return $rows;
}

/* ── Fingerprint to detect changes ── */
function fingerprint(array $rows): string {
    return md5(implode(',', array_map(fn($r) => $r['don_id'].':'.$r['status'], $rows)));
}

/* ── Stream loop ── */
$last_fp  = '';
$tick     = 0;
$max_ticks = 180;   // ~60 min max (180 × 20s), then client reconnects

while (!connection_aborted() && $tick < $max_ticks) {
    $rows = fetch_statuses($conn, $email);
    $fp   = fingerprint($rows);

    if ($fp !== $last_fp || $tick === 0) {
        sse_emit('status', ['donations' => $rows, 'ts' => time()]);
        $last_fp = $fp;
    }

    /* Keepalive ping every cycle */
    sse_emit('ping', ['ts' => time(), 'tick' => $tick]);

    $tick++;
    sleep(20);   // poll DB every 20 seconds — lightweight
}

/* Client will automatically reconnect (SSE spec default retry: 3s) */
sse_emit('close', ['reason' => 'max_duration', 'reconnect' => true]);
