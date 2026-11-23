<?php
// ============================================================
// Admin - Operational Analytics & Reporting Engine
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pageTitle = 'Reports & Operational Analytics';
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

// 1. Overall Aggregated Metrics
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

$summaryStmt = $pdo->prepare("SELECT 
    COUNT(*) as total_queues,
    SUM(CASE WHEN q.status = 'Completed' THEN 1 ELSE 0 END) as completed_queues,
    SUM(CASE WHEN q.status = 'Waiting' THEN 1 ELSE 0 END) as waiting_queues,
    SUM(CASE WHEN q.status = 'No-Show' THEN 1 ELSE 0 END) as noshow_queues,
    SUM(CASE WHEN q.status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_queues,
    SUM(CASE WHEN q.status = 'Transferred' THEN 1 ELSE 0 END) as transferred_queues,
    AVG(CASE WHEN q.called_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.registration_time, q.called_time) END) as avg_waiting_time,
    AVG(CASE WHEN q.status = 'Completed' AND q.service_start_time IS NOT NULL AND q.completed_time IS NOT NULL 
             THEN TIMESTAMPDIFF(MINUTE, q.service_start_time, q.completed_time) END) as avg_service_duration
FROM `queues` q 
WHERE {$baseWhere}");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$totalCount = (int)($summary['total_queues'] ?? 0);
$completedCount = (int)($summary['completed_queues'] ?? 0);
$waitingCount = (int)($summary['waiting_queues'] ?? 0);
$noshowCount = (int)($summary['noshow_queues'] ?? 0);
$cancelledCount = (int)($summary['cancelled_queues'] ?? 0);
$transferredCount = (int)($summary['transferred_queues'] ?? 0);
$avgWait = round((float)($summary['avg_waiting_time'] ?? 0), 1);
$avgDuration = round((float)($summary['avg_service_duration'] ?? 0), 1);

// Completion rate
$completionRate = $totalCount > 0 ? round(($completedCount / $totalCount) * 100, 1) : 0;

// 2. Service Performance Breakdown
$svcStmt = $pdo->prepare("SELECT 
    s.service_code,
    s.service_name,
    COUNT(q.id) as total_customers,
    SUM(CASE WHEN q.status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
    SUM(CASE WHEN q.status = 'No-Show' THEN 1 ELSE 0 END) as noshow_count,
    AVG(CASE WHEN q.called_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.registration_time, q.called_time) END) as avg_wait,
    AVG(CASE WHEN q.status = 'Completed' AND q.service_start_time IS NOT NULL AND q.completed_time IS NOT NULL 
             THEN TIMESTAMPDIFF(MINUTE, q.service_start_time, q.completed_time) END) as avg_duration
FROM `services` s
LEFT JOIN `queues` q ON s.id = q.service_id AND q.queue_date BETWEEN :sdate AND :edate
GROUP BY s.id, s.service_code, s.service_name
ORDER BY total_customers DESC");
$svcStmt->execute([':sdate' => $startDate, ':edate' => $endDate]);
$serviceStats = $svcStmt->fetchAll();

// 3. Counter & Staff Performance Breakdown
$cntStmt = $pdo->prepare("SELECT 
    c.counter_name,
    c.counter_code,
    u.name as staff_name,
    COUNT(q.id) as served_count,
    SUM(CASE WHEN q.status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
    SUM(CASE WHEN q.status = 'No-Show' THEN 1 ELSE 0 END) as noshow_count,
    AVG(CASE WHEN q.status = 'Completed' AND q.service_start_time IS NOT NULL AND q.completed_time IS NOT NULL 
             THEN TIMESTAMPDIFF(MINUTE, q.service_start_time, q.completed_time) END) as avg_duration
FROM `counters` c
LEFT JOIN `queues` q ON c.id = q.assigned_counter_id AND q.queue_date BETWEEN :sdate AND :edate
LEFT JOIN `users` u ON q.assigned_staff_id = u.id
GROUP BY c.id, c.counter_name, c.counter_code, u.name
ORDER BY c.id ASC");
$cntStmt->execute([':sdate' => $startDate, ':edate' => $endDate]);
$counterStats = $cntStmt->fetchAll();

// 4. Detailed Queue History (Paginated / Limited to 100 for fast rendering)
$historyStmt = $pdo->prepare("SELECT 
    q.queue_number,
    q.queue_date,
    q.status,
    q.registration_time,
    q.called_time,
    q.service_start_time,
    q.completed_time,
    s.service_name,
    s.service_code,
    p.priority_name,
    cnt.counter_name,
    u.name as staff_name
FROM `queues` q
JOIN `services` s ON q.service_id = s.id
JOIN `queue_priorities` p ON q.priority_id = p.id
LEFT JOIN `counters` cnt ON q.assigned_counter_id = cnt.id
LEFT JOIN `users` u ON q.assigned_staff_id = u.id
WHERE {$baseWhere}
ORDER BY q.id DESC
LIMIT 100");
$historyStmt->execute($params);
$historyRows = $historyStmt->fetchAll();

// Dropdowns data
$services = $pdo->query("SELECT * FROM `services` ORDER BY service_code ASC")->fetchAll();
$counters = $pdo->query("SELECT * FROM `counters` ORDER BY id ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="d-none d-print-block mb-4">
    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
        <div>
            <h3 class="fw-bold mb-0 text-dark"><?= e(get_setting('business_name', 'Smart City Service Center')) ?></h3>
            <h5 class="text-secondary mb-0">Operational Analytics & Queue Audit Report</h5>
        </div>
        <div class="text-end small text-muted">
            <div>Date Range: <strong><?= e($startDate) ?></strong> to <strong><?= e($endDate) ?></strong></div>
            <div>Generated: <strong><?= date('d M Y, H:i:s') ?></strong></div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2 no-print">
    <div>
        <h4 class="fw-bold mb-1">Operational Analytics & Queue Audit</h4>
        <p class="text-muted small mb-0">Inspect real database metrics across waiting times, throughput, and counter capacity.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/reports/export.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export to CSV
        </a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Summary
        </button>
    </div>
</div>

<!-- Filters Card -->
<div class="card border-0 shadow-sm rounded-3 mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted">Quick Range</label>
                <select name="preset" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="yesterday" <?= $preset === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                    <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>This Week</option>
                    <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                    <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom Dates</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= e($startDate) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= e($endDate) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted">Service</label>
                <select name="service_id" class="form-select form-select-sm">
                    <option value="0">All Services</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $serviceId === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= e($s['service_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted">Counter</label>
                <select name="counter_id" class="form-select form-select-sm">
                    <option value="0">All Counters</option>
                    <?php foreach ($counters as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $counterId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['counter_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel-fill"></i> Filter</button>
                <a href="<?= BASE_URL ?>/admin/reports/index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Aggregated Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Total Registered</div>
                <h3 class="fw-bold mb-0 text-dark"><?= $totalCount ?></h3>
                <small class="text-muted"><?= $completionRate ?>% completion rate</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Completed Services</div>
                <h3 class="fw-bold mb-0 text-success"><?= $completedCount ?></h3>
                <small class="text-muted"><?= $transferredCount ?> transferred</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Average Wait Time</div>
                <h3 class="fw-bold mb-0 text-primary"><?= $avgWait ?> <span class="fs-6 fw-normal text-muted">mins</span></h3>
                <small class="text-muted">From registration to call</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Avg Service Duration</div>
                <h3 class="fw-bold mb-0 text-info"><?= $avgDuration ?> <span class="fs-6 fw-normal text-muted">mins</span></h3>
                <small class="text-muted">Actual transaction time</small>
            </div>
        </div>
    </div>
</div>

<!-- Section: Service Breakdown & Counter Breakdown -->
<div class="row g-4 mb-4">
    <!-- Service Performance Table -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-tag-fill me-2 text-primary"></i>Service Performance Summary</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Service</th>
                                <th>Total</th>
                                <th>Completed</th>
                                <th>Avg Wait</th>
                                <th class="pe-3">Avg Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($serviceStats as $ss): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold">
                                        <span class="badge bg-dark me-1"><?= e($ss['service_code']) ?></span>
                                        <?= e($ss['service_name']) ?>
                                    </td>
                                    <td><?= (int)$ss['total_customers'] ?></td>
                                    <td><span class="badge bg-success bg-opacity-10 text-success"><?= (int)$ss['completed_count'] ?></span></td>
                                    <td class="small text-muted"><?= round((float)$ss['avg_wait'], 1) ?> mins</td>
                                    <td class="pe-3 small text-muted"><?= round((float)$ss['avg_duration'], 1) ?> mins</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Counter Performance Table -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-window-desktop me-2 text-primary"></i>Counter Performance Summary</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Counter</th>
                                <th>Officer</th>
                                <th>Completed</th>
                                <th>No-Show</th>
                                <th class="pe-3">Avg Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($counterStats as $cs): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= e($cs['counter_name']) ?></td>
                                    <td class="small text-muted"><?= e($cs['staff_name'] ?? 'Unassigned') ?></td>
                                    <td><span class="badge bg-success bg-opacity-10 text-success"><?= (int)$cs['completed_count'] ?></span></td>
                                    <td><span class="badge bg-danger bg-opacity-10 text-danger"><?= (int)$cs['noshow_count'] ?></span></td>
                                    <td class="pe-3 small text-muted"><?= round((float)$cs['avg_duration'], 1) ?> mins</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Queue Audit History Log -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white border-0 py-3">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Detailed Queue Log & Historical Records (Top 100)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Queue No</th>
                        <th>Date</th>
                        <th>Service</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Called</th>
                        <th>Served</th>
                        <th>Completed</th>
                        <th class="pe-3">Counter / Officer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyRows)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No records found for the selected time range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $hr): ?>
                            <tr>
                                <td class="ps-3 fw-bold"><?= e($hr['queue_number']) ?></td>
                                <td class="text-muted"><?= e($hr['queue_date']) ?></td>
                                <td><?= e($hr['service_name']) ?></td>
                                <td><span class="badge bg-secondary"><?= e($hr['status']) ?></span></td>
                                <td class="text-muted"><?= date('H:i:s', strtotime($hr['registration_time'])) ?></td>
                                <td class="text-muted"><?= $hr['called_time'] ? date('H:i:s', strtotime($hr['called_time'])) : '--' ?></td>
                                <td class="text-muted"><?= $hr['service_start_time'] ? date('H:i:s', strtotime($hr['service_start_time'])) : '--' ?></td>
                                <td class="text-muted"><?= $hr['completed_time'] ? date('H:i:s', strtotime($hr['completed_time'])) : '--' ?></td>
                                <td class="pe-3 text-muted"><?= e($hr['counter_name'] ?? 'None') ?> (<?= e($hr['staff_name'] ?? '--') ?>)</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
