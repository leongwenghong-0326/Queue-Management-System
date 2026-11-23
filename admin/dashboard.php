<?php
// ============================================================
// Admin - Dashboard Module
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/role_check.php';
require_role('Admin');

$pageTitle = 'Admin Dashboard';
$pdo = Database::getConnection();
$today = date('Y-m-d');

// 1. Metric Counts for Today
$stmt = $pdo->prepare("SELECT 
    COUNT(*) AS total_today,
    SUM(CASE WHEN `status` = 'Waiting' THEN 1 ELSE 0 END) AS waiting_count,
    SUM(CASE WHEN `status` = 'Called' THEN 1 ELSE 0 END) AS called_count,
    SUM(CASE WHEN `status` = 'Serving' THEN 1 ELSE 0 END) AS serving_count,
    SUM(CASE WHEN `status` = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
    SUM(CASE WHEN `status` = 'No-Show' THEN 1 ELSE 0 END) AS noshow_count,
    SUM(CASE WHEN `status` = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count
FROM `queues` 
WHERE `queue_date` = :today");
$stmt->execute([':today' => $today]);
$metrics = $stmt->fetch();

$totalToday = (int)($metrics['total_today'] ?? 0);
$waitingCount = (int)($metrics['waiting_count'] ?? 0);
$calledCount = (int)($metrics['called_count'] ?? 0);
$servingCount = (int)($metrics['serving_count'] ?? 0);
$completedCount = (int)($metrics['completed_count'] ?? 0);
$noshowCount = (int)($metrics['noshow_count'] ?? 0);
$cancelledCount = (int)($metrics['cancelled_count'] ?? 0);

// Active counters count
$activeCountersCount = (int)$pdo->query("SELECT COUNT(*) FROM `counters` WHERE `status` = 'active'")->fetchColumn();

// 2. Average Waiting & Service Durations for Today
$durationsStmt = $pdo->prepare("SELECT 
    AVG(CASE WHEN `called_time` IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, `registration_time`, `called_time`) END) AS avg_waiting_min,
    AVG(CASE WHEN `completed_time` IS NOT NULL AND `service_start_time` IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, `service_start_time`, `completed_time`) END) AS avg_service_min
FROM `queues` 
WHERE `queue_date` = :today");
$durationsStmt->execute([':today' => $today]);
$durations = $durationsStmt->fetch();
$avgWaiting = round((float)($durations['avg_waiting_min'] ?? 0), 1);
$avgService = round((float)($durations['avg_service_min'] ?? 0), 1);

// 3. Hourly Queue Breakdown for Chart
$hourlyStmt = $pdo->prepare("SELECT 
    HOUR(registration_time) AS reg_hour, 
    COUNT(*) AS total
FROM `queues` 
WHERE `queue_date` = :today 
GROUP BY HOUR(registration_time) 
ORDER BY reg_hour ASC");
$hourlyStmt->execute([':today' => $today]);
$hourlyData = $hourlyStmt->fetchAll();

$hoursMap = array_fill(8, 10, 0); // 8 AM to 5 PM default
foreach ($hourlyData as $row) {
    $h = (int)$row['reg_hour'];
    if (isset($hoursMap[$h])) {
        $hoursMap[$h] = (int)$row['total'];
    }
}

// 4. Service Distribution for Chart
$serviceDistStmt = $pdo->prepare("SELECT 
    s.service_name, 
    COUNT(q.id) AS total_count 
FROM `services` s
LEFT JOIN `queues` q ON s.id = q.service_id AND q.queue_date = :today
GROUP BY s.id, s.service_name");
$serviceDistStmt->execute([':today' => $today]);
$serviceDist = $serviceDistStmt->fetchAll();

// 5. Recent Activity Logs
$logsStmt = $pdo->query("SELECT a.*, u.name as user_name 
                         FROM `activity_logs` a 
                         LEFT JOIN `users` u ON a.user_id = u.id 
                         ORDER BY a.created_at DESC 
                         LIMIT 8");
$recentLogs = $logsStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Statistics Metric Cards -->
<div class="row g-2 g-md-3 mb-4">
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">Total Registered</span>
                    <span class="badge bg-primary bg-opacity-10 text-primary small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-calendar-event"></i> Today</span>
                </div>
                <h3 class="fw-bold mb-0 text-dark"><?= $totalToday ?></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">Waiting Queues</span>
                    <span class="badge bg-warning bg-opacity-10 text-warning small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-hourglass-split"></i> In Line</span>
                </div>
                <h3 class="fw-bold mb-0 text-warning"><?= $waitingCount ?></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">Called / In Transit</span>
                    <span class="badge bg-info bg-opacity-10 text-info small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-megaphone"></i> Called</span>
                </div>
                <h3 class="fw-bold mb-0 text-info"><?= $calledCount ?></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">Currently Serving</span>
                    <span class="badge bg-primary bg-opacity-10 text-primary small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-person-check-fill"></i> Counter</span>
                </div>
                <h3 class="fw-bold mb-0 text-primary"><?= $servingCount ?></h3>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">Completed Services</span>
                    <span class="badge bg-success bg-opacity-10 text-success small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-check2-circle"></i> Success</span>
                </div>
                <h3 class="fw-bold mb-0 text-success"><?= $completedCount ?></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">No-Show / Missed</span>
                    <span class="badge bg-danger bg-opacity-10 text-danger small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-person-x"></i> Absent</span>
                </div>
                <h3 class="fw-bold mb-0 text-danger"><?= $noshowCount ?></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">Average Wait Time</span>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-clock-history"></i> Speed</span>
                </div>
                <h3 class="fw-bold mb-0 text-dark"><?= $avgWaiting ?> <span class="fs-6 fw-normal text-muted">mins</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                    <span class="text-muted fw-semibold text-uppercase text-truncate" style="font-size: 0.72rem;">Avg Service Duration</span>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary small-badge-mobile d-none d-sm-inline-block"><i class="bi bi-stopwatch"></i> Duration</span>
                </div>
                <h3 class="fw-bold mb-0 text-dark"><?= $avgService ?> <span class="fs-6 fw-normal text-muted">mins</span></h3>
            </div>
        </div>
    </div>
</div>

<!-- Charts & Visual Analytics Section -->
<div class="row g-4 mb-4">
    <!-- Hourly Distribution Line Chart -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-graph-up me-2 text-primary"></i>Today's Hourly Queue Influx</h6>
                    <span class="badge bg-light text-muted border">Real-time DB Data</span>
                </div>
            </div>
            <div class="card-body">
                <canvas id="hourlyChart" height="260"></canvas>
            </div>
        </div>
    </div>

    <!-- Service Popularity Doughnut Chart -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Service Demand Share</h6>
            </div>
            <div class="card-body d-flex flex-column justify-content-center">
                <canvas id="serviceChart" height="240"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity Audit Log -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-check me-2 text-success"></i>Recent System & Operational Activity Logs</h6>
        <a href="<?= BASE_URL ?>/admin/reports/index.php" class="btn btn-sm btn-outline-primary">View Full Audit Report</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentLogs)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No activity logged yet today.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td class="ps-3 small text-muted"><?= e($log['created_at']) ?></td>
                                <td class="fw-semibold small"><?= e($log['user_name'] ?? 'System / Anonymous') ?></td>
                                <td><span class="badge bg-secondary"><?= e($log['action']) ?></span></td>
                                <td class="small text-muted"><?= e($log['description']) ?></td>
                                <td class="small text-muted"><code><?= e($log['ip_address']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    // 1. Hourly Chart
    const hoursLabels = <?= json_encode(array_map(function($h) { return sprintf("%02d:00", $h); }, array_keys($hoursMap))) ?>;
    const hoursValues = <?= json_encode(array_values($hoursMap)) ?>;

    const ctxHourly = document.getElementById('hourlyChart').getContext('2d');
    new Chart(ctxHourly, {
        type: 'line',
        data: {
            labels: hoursLabels,
            datasets: [{
                label: 'Registered Queues',
                data: hoursValues,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.35,
                borderWidth: 2,
                pointRadius: 4,
                pointBackgroundColor: '#0d6efd'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });

    // 2. Service Demand Chart
    const serviceLabels = <?= json_encode(array_column($serviceDist, 'service_name')) ?>;
    const serviceValues = <?= json_encode(array_map('intval', array_column($serviceDist, 'total_count'))) ?>;

    const ctxService = document.getElementById('serviceChart').getContext('2d');
    new Chart(ctxService, {
        type: 'doughnut',
        data: {
            labels: serviceLabels,
            datasets: [{
                data: serviceValues,
                backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#0dcaf0', '#6f42c1', '#d63384'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
