<?php
// ============================================================
// Admin - Real-Time Queue Monitor & Audit Overview
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pageTitle = 'Live Queue Monitor';
$pdo = Database::getConnection();

// Handle Admin Actions (e.g., Cancel Queue, Elevate Priority)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $queueId = (int)($_POST['queue_id'] ?? 0);

    if ($action === 'cancel' && $queueId > 0) {
        $stmt = $pdo->prepare("SELECT status FROM `queues` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $queueId]);
        $currStatus = $stmt->fetchColumn();

        if ($currStatus && !in_array($currStatus, ['Completed', 'Cancelled'])) {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("UPDATE `queues` SET `status` = 'Cancelled' WHERE `id` = :id");
                $upd->execute([':id' => $queueId]);
                record_queue_status_transition($pdo, $queueId, $currStatus, 'Cancelled', $_SESSION['user_id'], 'Administrative manual cancellation');
                $pdo->commit();
                log_activity($_SESSION['user_id'], 'QUEUE_CANCELLED_BY_ADMIN', "Cancelled queue ID: {$queueId}");
                set_flash('success', "Queue ticket ID #{$queueId} marked as Cancelled.");
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('danger', 'Cancellation failed: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/admin/queues/index.php');
        exit();
    } elseif ($action === 'set_priority' && $queueId > 0) {
        $priorityId = (int)($_POST['priority_id'] ?? 1);
        $upd = $pdo->prepare("UPDATE `queues` SET `priority_id` = :pid WHERE `id` = :id AND `status` = 'Waiting'");
        $upd->execute([':pid' => $priorityId, ':id' => $queueId]);
        log_activity($_SESSION['user_id'], 'QUEUE_PRIORITY_CHANGED', "Adjusted priority for queue ID: {$queueId} to priority ID {$priorityId}");
        set_flash('success', "Queue priority updated.");
        header('Location: ' . BASE_URL . '/admin/queues/index.php');
        exit();
    }
}

// Filters
$filterDate = $_GET['date'] ?? date('Y-m-d');
$filterService = (int)($_GET['service_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';

$query = "SELECT q.*, s.service_code, s.service_name, c.customer_name, c.phone, 
                 p.priority_name, p.priority_level,
                 cnt.counter_name, u.name as staff_name
          FROM `queues` q
          JOIN `services` s ON q.service_id = s.id
          LEFT JOIN `customers` c ON q.customer_id = c.id
          LEFT JOIN `queue_priorities` p ON q.priority_id = p.id
          LEFT JOIN `counters` cnt ON q.assigned_counter_id = cnt.id
          LEFT JOIN `users` u ON q.assigned_staff_id = u.id
          WHERE q.queue_date = :fdate";

$params = [':fdate' => $filterDate];

if ($filterService > 0) {
    $query .= " AND q.service_id = :sid";
    $params[':sid'] = $filterService;
}
if (!empty($filterStatus)) {
    $query .= " AND q.status = :status";
    $params[':status'] = $filterStatus;
}

$query .= " ORDER BY q.id DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$queues = $stmt->fetchAll();

// Dropdowns data
$services = $pdo->query("SELECT * FROM `services` ORDER BY service_code ASC")->fetchAll();
$priorities = $pdo->query("SELECT * FROM `queue_priorities` WHERE status = 'active' ORDER BY priority_level ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Live Queue Control & Audit</h4>
        <p class="text-muted small mb-0">Real-time status tracking, priority escalation, and queue audit trails.</p>
    </div>
</div>

<!-- Filters Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted">Queue Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted">Service Filter</label>
                <select name="service_id" class="form-select form-select-sm">
                    <option value="0">All Services</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filterService === (int)$s['id'] ? 'selected' : '' ?>>
                            [<?= e($s['service_code']) ?>] <?= e($s['service_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="Waiting" <?= $filterStatus === 'Waiting' ? 'selected' : '' ?>>Waiting</option>
                    <option value="Called" <?= $filterStatus === 'Called' ? 'selected' : '' ?>>Called</option>
                    <option value="Serving" <?= $filterStatus === 'Serving' ? 'selected' : '' ?>>Serving</option>
                    <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="No-Show" <?= $filterStatus === 'No-Show' ? 'selected' : '' ?>>No-Show</option>
                    <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="Transferred" <?= $filterStatus === 'Transferred' ? 'selected' : '' ?>>Transferred</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-filter"></i> Apply Filters</button>
                <a href="<?= BASE_URL ?>/admin/queues/index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Queues Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Number</th>
                        <th>Service</th>
                        <th>Customer</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Est. Wait</th>
                        <th>Registered</th>
                        <th>Counter / Officer</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($queues)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No queue records match the selected criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($queues as $q): ?>
                            <?php
                            $estDisplay = '--';
                            if ($q['status'] === 'Waiting') {
                                $qEst = get_detailed_queue_estimate_data((int)$q['service_id'], (int)$q['id']);
                                $estDisplay = '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size: 0.72rem;"><i class="bi bi-clock me-1"></i>' . e($qEst['estimate_label']) . '</span>';
                            } elseif (in_array($q['status'], ['Called', 'Serving'])) {
                                $estDisplay = '<span class="badge bg-light text-muted border" style="font-size: 0.72rem;">0 mins (Active)</span>';
                            }
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <span class="fw-bold fs-5 text-dark"><?= e($q['queue_number']) ?></span>
                                    <?php if ($q['transfer_from_queue_id']): ?>
                                        <span class="badge bg-purple text-white" style="background-color: #6f42c1;" title="Transferred from previous queue">Transfer</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($q['service_name']) ?></div>
                                    <small class="text-muted">[Code: <?= e($q['service_code']) ?>]</small>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= e($q['customer_name'] ?? 'Walk-in Customer') ?></div>
                                    <small class="text-muted"><?= e($q['phone'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <span class="badge <?= $q['priority_level'] > 1 ? 'bg-danger' : 'bg-secondary' ?>">
                                        <?= e($q['priority_name'] ?? 'Normal') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = match($q['status']) {
                                        'Waiting' => 'bg-warning text-dark',
                                        'Called' => 'bg-info text-dark',
                                        'Serving' => 'bg-primary text-white',
                                        'Completed' => 'bg-success text-white',
                                        'No-Show' => 'bg-danger text-white',
                                        'Cancelled' => 'bg-secondary text-white',
                                        'Transferred' => 'text-white" style="background-color: #6f42c1;',
                                        default => 'bg-light text-dark'
                                    };
                                    ?>
                                    <span class="badge <?= $statusClass ?>"><?= e($q['status']) ?></span>
                                </td>
                                <td><?= $estDisplay ?></td>
                                <td class="small text-muted"><?= date('H:i:s', strtotime($q['registration_time'])) ?></td>
                                <td>
                                    <?php if ($q['counter_name']): ?>
                                        <div class="small fw-semibold text-dark"><?= e($q['counter_name']) ?></div>
                                        <small class="text-muted"><?= e($q['staff_name']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Waiting assignment</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <?php if ($q['status'] === 'Waiting'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="openCancelModal(<?= $q['id'] ?>, '<?= e($q['queue_number']) ?>')">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Cancel Queue -->
<div class="modal fade" id="cancelQueueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="queue_id" id="cancel_queue_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">Cancel Queue Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel ticket <strong id="cancel_queue_num"></strong>?</p>
                    <p class="small text-muted mb-0">This queue ticket will be marked as Cancelled and will not be called by any counter officer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openCancelModal(id, number) {
    document.getElementById('cancel_queue_id').value = id;
    document.getElementById('cancel_queue_num').textContent = number;
    new bootstrap.Modal(document.getElementById('cancelQueueModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
