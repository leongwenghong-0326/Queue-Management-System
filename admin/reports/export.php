<?php
// ============================================================
// Admin - Reports CSV Exporter
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pdo = Database::getConnection();

// Date Range Filtering
$preset = $_GET['preset'] ?? 'today';
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$serviceId = (int)($_GET['service_id'] ?? 0);
$counterId = (int)($_GET['counter_id'] ?? 0);

if ($preset === 'yesterday') {
    $startDate = date('Y-m-d', strtotime('-1 day'));
    $endDate = $startDate;
} elseif ($preset === 'this_week') {
    $startDate = date('Y-m-d', strtotime('monday this week'));
    $endDate = date('Y-m-d');
} elseif ($preset === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

$baseWhere = "q.queue_date BETWEEN :sdate AND :edate";
$params = [':sdate' => $startDate, ':edate' => $endDate];

if ($serviceId > 0) {
    $baseWhere .= " AND q.service_id = :sid";
    $params[':sid'] = $serviceId;
}
if ($counterId > 0) {
    $baseWhere .= " AND q.assigned_counter_id = :cid";
    $params[':cid'] = $counterId;
}

$stmt = $pdo->prepare("SELECT 
    q.id,
    q.queue_number,
    q.queue_date,
    s.service_code,
    s.service_name,
    p.priority_name,
    c.customer_name,
    c.phone,
    q.status,
    q.registration_time,
    q.called_time,
    q.service_start_time,
    q.completed_time,
    cnt.counter_name,
    u.name as staff_name,
    TIMESTAMPDIFF(MINUTE, q.registration_time, q.called_time) as wait_minutes,
    TIMESTAMPDIFF(MINUTE, q.service_start_time, q.completed_time) as service_minutes
FROM `queues` q
JOIN `services` s ON q.service_id = s.id
JOIN `queue_priorities` p ON q.priority_id = p.id
LEFT JOIN `customers` c ON q.customer_id = c.id
LEFT JOIN `counters` cnt ON q.assigned_counter_id = cnt.id
LEFT JOIN `users` u ON q.assigned_staff_id = u.id
WHERE {$baseWhere}
ORDER BY q.id ASC");

$stmt->execute($params);

$filename = "queue_report_" . date('Ymd_His') . ".csv";

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Output UTF-8 BOM for Microsoft Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, [
    'Queue ID',
    'Queue Number',
    'Date',
    'Service Code',
    'Service Name',
    'Priority',
    'Customer Name',
    'Customer Phone',
    'Status',
    'Registered Time',
    'Called Time',
    'Service Start Time',
    'Completed Time',
    'Assigned Counter',
    'Staff Officer',
    'Wait Duration (Mins)',
    'Service Duration (Mins)'
]);

while ($row = $stmt->fetch()) {
    fputcsv($output, [
        $row['id'],
        $row['queue_number'],
        $row['queue_date'],
        $row['service_code'],
        $row['service_name'],
        $row['priority_name'],
        $row['customer_name'] ?? 'Walk-in Customer',
        $row['phone'] ?? 'N/A',
        $row['status'],
        $row['registration_time'],
        $row['called_time'] ?? '',
        $row['service_start_time'] ?? '',
        $row['completed_time'] ?? '',
        $row['counter_name'] ?? 'None',
        $row['staff_name'] ?? 'None',
        $row['wait_minutes'] ?? 0,
        $row['service_minutes'] ?? 0
    ]);
}

fclose($output);
exit();
