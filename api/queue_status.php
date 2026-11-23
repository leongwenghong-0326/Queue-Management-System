<?php
// ============================================================
// API - Queue Live Status Polling Endpoint
// ============================================================

require_once __DIR__ . '/../config/config.php';

$pdo = Database::getConnection();
$today = date('Y-m-d');

$rawToken = trim($_GET['token'] ?? '');
$queueId = (int)($_GET['queue_id'] ?? 0);

$queue = null;

if (!empty($rawToken)) {
    $tokenHash = hash_tracking_token($rawToken);
    $stmt = $pdo->prepare("SELECT q.id, q.queue_number, q.service_id, q.priority_id, q.status, q.registration_time,
                                  cnt.counter_name, cnt.counter_code, s.service_name
                           FROM `queues` q
                           JOIN `services` s ON q.service_id = s.id
                           LEFT JOIN `counters` cnt ON q.assigned_counter_id = cnt.id
                           WHERE q.tracking_token_hash = :thash LIMIT 1");
    $stmt->execute([':thash' => $tokenHash]);
    $queue = $stmt->fetch();
} elseif ($queueId > 0 && !empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT q.id, q.queue_number, q.service_id, q.priority_id, q.status, q.registration_time,
                                  cnt.counter_name, cnt.counter_code, s.service_name
                           FROM `queues` q
                           JOIN `services` s ON q.service_id = s.id
                           LEFT JOIN `counters` cnt ON q.assigned_counter_id = cnt.id
                           WHERE q.id = :qid LIMIT 1");
    $stmt->execute([':qid' => $queueId]);
    $queue = $stmt->fetch();
}

if (!$queue) {
    json_response(['success' => false, 'message' => 'Queue record not found or unauthorized.'], 404);
}

// Compute detailed dynamic estimate using multi-server workload model
$estimate = get_detailed_queue_estimate_data((int)$queue['service_id'], (int)$queue['id']);

json_response([
    'success' => true,
    'queue' => $queue,
    'customers_ahead' => $estimate['customers_ahead'],
    'estimated_wait_minutes' => $estimate['estimated_wait_minutes'],
    'active_counters_count' => $estimate['active_counters_count'],
    'is_next_in_line' => $estimate['is_next_in_line'],
    'estimate_label' => $estimate['estimate_label'],
    'server_time' => date('Y-m-d H:i:s')
]);
