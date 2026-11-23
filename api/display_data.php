<?php
// ============================================================
// API - Real-Time Public Display Data Endpoint
// ============================================================

require_once __DIR__ . '/../config/config.php';

$pdo = Database::getConnection();
$today = date('Y-m-d');

// 1. Currently Calling & Serving at all active counters
$servingStmt = $pdo->prepare("SELECT 
    q.id,
    q.queue_number, 
    q.status,
    q.called_time,
    c.id as counter_id,
    c.counter_name, 
    c.counter_code,
    s.service_name,
    s.service_code
FROM `counters` c
LEFT JOIN `queues` q ON c.id = q.assigned_counter_id 
                    AND q.queue_date = :today 
                    AND q.status IN ('Called', 'Serving')
LEFT JOIN `services` s ON q.service_id = s.id
WHERE c.status = 'active'
ORDER BY c.id ASC");
$servingStmt->execute([':today' => $today]);
$servingList = $servingStmt->fetchAll();

// 2. Recently Called Queues (From queue_calls table)
$recentStmt = $pdo->prepare("SELECT 
    qc.id as call_id,
    qc.called_at,
    qc.call_type,
    q.queue_number,
    c.counter_name,
    s.service_name
FROM `queue_calls` qc
JOIN `queues` q ON qc.queue_id = q.id
JOIN `counters` c ON qc.counter_id = c.id
JOIN `services` s ON q.service_id = s.id
WHERE q.queue_date = :today
ORDER BY qc.id DESC
LIMIT 8");
$recentStmt->execute([':today' => $today]);
$recentCalls = $recentStmt->fetchAll();

// Latest Call ID for trigger comparison
$latestCall = $recentCalls[0] ?? null;

// 3. Waiting Summary by Service
$summaryStmt = $pdo->prepare("SELECT 
    s.id,
    s.service_code, 
    s.service_name, 
    COUNT(CASE WHEN q.status = 'Waiting' AND q.queue_date = :today THEN 1 END) AS waiting_count
FROM `services` s
LEFT JOIN `queues` q ON s.id = q.service_id
WHERE s.status = 'active'
GROUP BY s.id, s.service_code, s.service_name
ORDER BY s.service_code ASC");
$summaryStmt->execute([':today' => $today]);
$waitingSummary = $summaryStmt->fetchAll();

json_response([
    'success' => true,
    'currently_serving' => $servingList,
    'recently_called' => $recentCalls,
    'latest_call' => $latestCall,
    'waiting_summary' => $waitingSummary,
    'server_time' => date('Y-m-d H:i:s')
]);
