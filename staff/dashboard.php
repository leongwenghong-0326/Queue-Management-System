<?php
// ============================================================
// Staff - Counter Terminal Dashboard
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

$pageTitle = 'Counter Terminal Workspace';
$pdo = Database::getConnection();
$staffId = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

// Handle Counter Switch Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_counter_id'])) {
    require_csrf();
    $newCounterId = (int)$_POST['switch_counter_id'];
    
    // Verify counter exists and active
    $chk = $pdo->prepare("SELECT id FROM `counters` WHERE `id` = :id AND `status` = 'active'");
    $chk->execute([':id' => $newCounterId]);
    if ($chk->fetch()) {
        $_SESSION['active_counter_id'] = $newCounterId;
        // Record session
        $sessStmt = $pdo->prepare("INSERT INTO `counter_sessions` (`counter_id`, `staff_id`, `started_at`, `status`) VALUES (:cid, :sid, NOW(), 'active')");
        $sessStmt->execute([':cid' => $newCounterId, ':sid' => $staffId]);
        set_flash('success', "Active counter switched successfully.");
    }
    header('Location: ' . BASE_URL . '/staff/dashboard.php');
    exit();
}

// 1. Determine Active Counter
$activeCounterId = (int)($_SESSION['active_counter_id'] ?? 0);

// If not set in session, find counter assigned to this staff member
if ($activeCounterId <= 0) {
    $assignStmt = $pdo->prepare("SELECT counter_id FROM `staff_counter_assignments` WHERE `staff_id` = :sid AND `status` = 'active' LIMIT 1");
    $assignStmt->execute([':sid' => $staffId]);
    $assignedCid = (int)$assignStmt->fetchColumn();
    if ($assignedCid > 0) {
        $activeCounterId = $assignedCid;
        $_SESSION['active_counter_id'] = $activeCounterId;
    } else {
        // Fallback to first available active counter
        $firstCid = (int)$pdo->query("SELECT id FROM `counters` WHERE `status` = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstCid > 0) {
            $activeCounterId = $firstCid;
            $_SESSION['active_counter_id'] = $activeCounterId;
        }
    }
}

// Get active counter details
$counter = null;
if ($activeCounterId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM `counters` WHERE `id` = :id");
    $stmt->execute([':id' => $activeCounterId]);
    $counter = $stmt->fetch();
}

// All active counters for dropdown selection
$allCounters = $pdo->query("SELECT * FROM `counters` WHERE `status` = 'active' ORDER BY id ASC")->fetchAll();

// 2. Fetch Active Queue at this Counter (Called or Serving)
$activeQueue = null;
$callCount = 0;
if ($activeCounterId > 0) {
    $aqStmt = $pdo->prepare("SELECT q.*, s.service_name, s.service_code, p.priority_name, p.priority_level, c.customer_name, c.phone 
                            FROM `queues` q 
                            JOIN `services` s ON q.service_id = s.id 
                            LEFT JOIN `queue_priorities` p ON q.priority_id = p.id 
                            LEFT JOIN `customers` c ON q.customer_id = c.id 
                            WHERE q.assigned_counter_id = :cid 
                              AND q.queue_date = :today 
                              AND q.status IN ('Called', 'Serving') 
                            LIMIT 1");
    $aqStmt->execute([':cid' => $activeCounterId, ':today' => $today]);
    $activeQueue = $aqStmt->fetch();

    if ($activeQueue) {
        $ccStmt = $pdo->prepare("SELECT COUNT(*) FROM `queue_calls` WHERE `queue_id` = :qid AND `call_type` = 'Recall'");
        $ccStmt->execute([':qid' => $activeQueue['id']]);
        $callCount = (int)$ccStmt->fetchColumn();
    }
}

// 3. Authorized Services for this counter
$authorizedServices = [];
$authorizedServiceIds = [];
if ($activeCounterId > 0) {
    $asStmt = $pdo->prepare("SELECT s.* 
                            FROM `counter_services` cs 
                            JOIN `services` s ON cs.service_id = s.id 
                            WHERE cs.counter_id = :cid AND s.status = 'active'");
    $asStmt->execute([':cid' => $activeCounterId]);
    $authorizedServices = $asStmt->fetchAll();
    $authorizedServiceIds = array_column($authorizedServices, 'id');
}

// 4. Waiting Queue for authorized services
$waitingQueues = [];
if (!empty($authorizedServiceIds)) {
    $inClause = implode(',', array_map('intval', $authorizedServiceIds));
    $wqStmt = $pdo->prepare("SELECT q.*, s.service_name, s.service_code, p.priority_name, p.priority_level, c.customer_name 
                            FROM `queues` q 
                            JOIN `services` s ON q.service_id = s.id 
                            JOIN `queue_priorities` p ON q.priority_id = p.id 
                            LEFT JOIN `customers` c ON q.customer_id = c.id 
                            WHERE q.service_id IN ({$inClause}) 
                              AND q.queue_date = :today 
                              AND q.status = 'Waiting' 
                            ORDER BY p.priority_level DESC, q.registration_time ASC 
                            LIMIT 15");
    $wqStmt->execute([':today' => $today]);
    $waitingQueues = $wqStmt->fetchAll();
}

// 5. Today's Counter Statistics
$counterStats = [
    'completed' => 0,
    'noshow' => 0,
    'avg_service_min' => 0
];
if ($activeCounterId > 0) {
    $statStmt = $pdo->prepare("SELECT 
        SUM(CASE WHEN `status` = 'Completed' THEN 1 ELSE 0 END) as comp_count,
        SUM(CASE WHEN `status` = 'No-Show' THEN 1 ELSE 0 END) as noshow_count,
        AVG(CASE WHEN `status` = 'Completed' AND `service_start_time` IS NOT NULL AND `completed_time` IS NOT NULL 
                 THEN TIMESTAMPDIFF(MINUTE, `service_start_time`, `completed_time`) END) as avg_min
    FROM `queues` 
    WHERE `assigned_counter_id` = :cid AND `queue_date` = :today");
    $statStmt->execute([':cid' => $activeCounterId, ':today' => $today]);
    $cRow = $statStmt->fetch();
    $counterStats['completed'] = (int)($cRow['comp_count'] ?? 0);
    $counterStats['noshow'] = (int)($cRow['noshow_count'] ?? 0);
    $counterStats['avg_service_min'] = round((float)($cRow['avg_min'] ?? 0), 1);
}

// All services for Transfer Modal
$allActiveServices = $pdo->query("SELECT * FROM `services` WHERE `status` = 'active' ORDER BY service_code ASC")->fetchAll();

$maxRecalls = (int)get_setting('max_recall_attempts', '3');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Top Station Bar -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white p-3 rounded-4 me-3">
                <i class="bi bi-display fs-2"></i>
            </div>
            <div>
                <span class="badge bg-success bg-opacity-10 text-success mb-1">
                    <i class="bi bi-circle-fill me-1" style="font-size: 8px;"></i> Online & Operating
                </span>
                <h4 class="fw-bold mb-0 text-dark"><?= e($counter['counter_name'] ?? 'No Counter Selected') ?></h4>
                <small class="text-muted"><?= e($counter['location'] ?? 'Service Area') ?></small>
            </div>
        </div>

        <!-- Station Switch Form -->
        <form method="POST" action="" class="d-flex align-items-center gap-2">
            <?= csrf_field() ?>
            <label class="small fw-semibold text-muted text-nowrap">Switch Station:</label>
            <select name="switch_counter_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($allCounters as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $c['id'] === $activeCounterId ? 'selected' : '' ?>>
                        <?= e($c['counter_name']) ?> (<?= e($c['counter_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="row g-4">
    <!-- Main Center: Current Queue Action Hub -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-broadcast-pin text-primary me-2"></i>Active Serving Console
                    </h6>
                    <?php if ($activeQueue): ?>
                        <span class="badge <?= $activeQueue['status'] === 'Called' ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fs-6 px-3">
                            Status: <?= e($activeQueue['status']) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-light text-muted border">Counter Idle</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-body p-4 text-center d-flex flex-column justify-content-center">
                <?php if ($activeQueue): ?>
                    
                    <div class="p-3 bg-light rounded-4 mb-4 border">
                        <div class="text-muted small text-uppercase fw-semibold mb-1">Ticket In Progress</div>
                        <div class="queue-number-display fw-bold text-primary my-2">
                            <?= e($activeQueue['queue_number']) ?>
                        </div>
                        <span class="badge bg-primary bg-opacity-10 text-primary fs-6 px-3 py-1 mb-2">
                            <?= e($activeQueue['service_name']) ?>
                        </span>
                        
                        <div class="row g-2 justify-content-center text-muted small mt-2">
                            <div class="col-auto">
                                <i class="bi bi-person"></i> <?= e($activeQueue['customer_name'] ?? 'Walk-in Customer') ?>
                            </div>
                            <?php if (!empty($activeQueue['phone'])): ?>
                                <div class="col-auto">
                                    <i class="bi bi-phone"></i> <?= e($activeQueue['phone']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="col-auto">
                                <i class="bi bi-star-fill text-warning"></i> <?= e($activeQueue['priority_name'] ?? 'Normal') ?>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-clock"></i> Called at: <?= date('h:i:s A', strtotime($activeQueue['called_time'])) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Action Control Buttons Depending on Status -->
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <?php if ($activeQueue['status'] === 'Called'): ?>
                            <!-- Recall Button -->
                            <form method="POST" action="<?= BASE_URL ?>/staff/recall.php" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="queue_id" value="<?= $activeQueue['id'] ?>">
                                <input type="hidden" name="counter_id" value="<?= $activeCounterId ?>">
                                <button type="submit" class="btn btn-outline-warning fw-semibold px-3 py-2 text-dark" <?= $callCount >= $maxRecalls ? 'disabled' : '' ?>>
                                    <i class="bi bi-megaphone-fill me-1"></i> Recall (<?= $callCount ?>/<?= $maxRecalls ?>)
                                </button>
                            </form>

                            <!-- Start Serving Button -->
                            <form method="POST" action="<?= BASE_URL ?>/staff/start_serving.php" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="queue_id" value="<?= $activeQueue['id'] ?>">
                                <input type="hidden" name="counter_id" value="<?= $activeCounterId ?>">
                                <button type="submit" class="btn btn-success fw-bold px-4 py-2 shadow-sm">
                                    <i class="bi bi-play-circle-fill me-1"></i> Start Serving
                                </button>
                            </form>

                            <!-- Mark No-Show Button -->
                            <button type="button" class="btn btn-outline-danger fw-semibold px-3 py-2" data-bs-toggle="modal" data-bs-target="#noShowModal">
                                <i class="bi bi-person-x-fill me-1"></i> No-Show
                            </button>

                            <!-- Transfer Button -->
                            <button type="button" class="btn btn-outline-secondary fw-semibold px-3 py-2" data-bs-toggle="modal" data-bs-target="#transferModal">
                                <i class="bi bi-arrow-left-right me-1"></i> Transfer
                            </button>

                        <?php elseif ($activeQueue['status'] === 'Serving'): ?>
                            
                            <!-- Complete Service Button -->
                            <form method="POST" action="<?= BASE_URL ?>/staff/complete.php" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="queue_id" value="<?= $activeQueue['id'] ?>">
                                <input type="hidden" name="counter_id" value="<?= $activeCounterId ?>">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold px-5 py-3 shadow">
                                    <i class="bi bi-check2-circle me-1"></i> Complete Service
                                </button>
                            </form>

                            <!-- Transfer Button -->
                            <button type="button" class="btn btn-outline-secondary fw-semibold px-4 py-3 ms-2" data-bs-toggle="modal" data-bs-target="#transferModal">
                                <i class="bi bi-arrow-left-right me-1"></i> Transfer
                            </button>

                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    
                    <!-- Counter is Idle: Big "Call Next" Button -->
                    <div class="py-5">
                        <div class="d-inline-flex p-4 bg-primary bg-opacity-10 text-primary rounded-circle mb-3">
                            <i class="bi bi-bell fs-1"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-2">Counter Ready</h4>
                        <p class="text-muted small mb-4">Click below to retrieve and call the next eligible customer waiting in line.</p>
                        
                        <form method="POST" action="<?= BASE_URL ?>/staff/call_next.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="counter_id" value="<?= $activeCounterId ?>">
                            <button type="submit" class="btn btn-primary btn-lg px-5 py-3 fw-bold rounded-pill shadow">
                                <i class="bi bi-megaphone me-2"></i> Call Next Customer
                            </button>
                        </form>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Side: Waiting Queue & Today's Station Performance -->
    <div class="col-lg-5">
        <!-- Daily Counter Statistics -->
        <div class="row g-2 mb-4">
            <div class="col-4">
                <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
                    <div class="fs-4 fw-bold text-success"><?= $counterStats['completed'] ?></div>
                    <div class="small text-muted text-uppercase" style="font-size: 0.7rem;">Completed</div>
                </div>
            </div>
            <div class="col-4">
                <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
                    <div class="fs-4 fw-bold text-danger"><?= $counterStats['noshow'] ?></div>
                    <div class="small text-muted text-uppercase" style="font-size: 0.7rem;">No-Shows</div>
                </div>
            </div>
            <div class="col-4">
                <div class="card border-0 shadow-sm rounded-3 p-3 text-center">
                    <div class="fs-4 fw-bold text-dark"><?= $counterStats['avg_service_min'] ?>m</div>
                    <div class="small text-muted text-uppercase" style="font-size: 0.7rem;">Avg Duration</div>
                </div>
            </div>
        </div>

        <!-- Waiting Queue List -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-list-task text-primary me-2"></i>Waiting Queue (<?= count($waitingQueues) ?>)
                </h6>
                <button class="btn btn-sm btn-link text-decoration-none p-0" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3">Queue No</th>
                                <th>Service</th>
                                <th>Priority</th>
                                <th>Est. Wait</th>
                                <th class="pe-3 text-end">Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($waitingQueues)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted small">No customers waiting currently.</td></tr>
                            <?php else: ?>
                                <?php foreach ($waitingQueues as $wq): ?>
                                    <?php 
                                        $waitMin = round((time() - strtotime($wq['registration_time'])) / 60);
                                        $estWaitItem = calculate_estimated_waiting_time((int)$wq['service_id'], (int)$wq['id']);
                                    ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary"><?= e($wq['queue_number']) ?></td>
                                        <td class="small"><?= e($wq['service_name']) ?></td>
                                        <td>
                                            <span class="badge <?= $wq['priority_level'] > 1 ? 'bg-danger' : 'bg-secondary' ?>" style="font-size: 0.7rem;">
                                                <?= e($wq['priority_name'] ?? 'Normal') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size: 0.72rem;">
                                                <i class="bi bi-hourglass-split me-1"></i>~<?= $estWaitItem ?> mins
                                            </span>
                                        </td>
                                        <td class="pe-3 text-end small text-muted"><?= $waitMin ?>m ago</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Mark No-Show -->
<?php if ($activeQueue): ?>
<div class="modal fade" id="noShowModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= BASE_URL ?>/staff/no_show.php">
                <?= csrf_field() ?>
                <input type="hidden" name="queue_id" value="<?= $activeQueue['id'] ?>">
                <input type="hidden" name="counter_id" value="<?= $activeCounterId ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">Mark as No-Show</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Mark customer <strong><?= e($activeQueue['queue_number']) ?></strong> as No-Show?</p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Reason for Absence</label>
                        <select name="reason" class="form-select">
                            <option value="Customer did not appear after maximum call attempts">Customer did not appear after maximum call attempts</option>
                            <option value="Customer departed waiting area">Customer departed waiting area</option>
                            <option value="Called multiple times with no response">Called multiple times with no response</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm No-Show</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Transfer Queue -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= BASE_URL ?>/staff/transfer.php">
                <?= csrf_field() ?>
                <input type="hidden" name="queue_id" value="<?= $activeQueue['id'] ?>">
                <input type="hidden" name="counter_id" value="<?= $activeCounterId ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Transfer Queue Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Transferring ticket <strong><?= e($activeQueue['queue_number']) ?></strong> to another service.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Destination Service</label>
                        <select name="destination_service_id" class="form-select" required>
                            <option value="">-- Choose Target Service --</option>
                            <?php foreach ($allActiveServices as $as): ?>
                                <?php if ($as['id'] !== $activeQueue['service_id']): ?>
                                    <option value="<?= $as['id'] ?>">
                                        [<?= e($as['service_code']) ?>] <?= e($as['service_name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Transfer Reason</label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g. Requires additional payment settlement" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Process Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
