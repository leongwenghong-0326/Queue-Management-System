<?php
// ============================================================
// Admin - Appointments Management
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pageTitle = 'Appointments Management';
$pdo = Database::getConnection();

// Handle Actions (Check-in, Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $aptId = (int)($_POST['appointment_id'] ?? 0);

    if ($action === 'checkin' && $aptId > 0) {
        $stmt = $pdo->prepare("SELECT appointment_reference FROM `appointments` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $aptId]);
        $ref = $stmt->fetchColumn();
        if ($ref) {
            $res = checkin_appointment($ref);
            if ($res['success']) {
                set_flash('success', "Appointment {$ref} checked in successfully. Ticket: {$res['queue_number']}");
            } else {
                set_flash('danger', $res['message']);
            }
        }
    } elseif ($action === 'cancel' && $aptId > 0) {
        $stmt = $pdo->prepare("UPDATE `appointments` SET `status` = 'Cancelled' WHERE `id` = :id");
        $stmt->execute([':id' => $aptId]);
        log_activity($_SESSION['user_id'], 'ADMIN_CANCEL_APPOINTMENT', "Cancelled appointment ID: {$aptId}");
        set_flash('success', "Appointment #{$aptId} marked as Cancelled.");
    }
    header('Location: ' . BASE_URL . '/admin/appointments/index.php');
    exit();
}

// Filters
$filterDate = $_GET['date'] ?? '';
$filterService = (int)($_GET['service_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$services = $pdo->query("SELECT * FROM `services` ORDER BY `service_code` ASC")->fetchAll();

$sql = "SELECT a.*, s.service_code, s.service_name, u.username as user_account, q.queue_number 
        FROM `appointments` a 
        JOIN `services` s ON a.service_id = s.id 
        LEFT JOIN `users` u ON a.user_id = u.id 
        LEFT JOIN `queues` q ON a.queue_id = q.id 
        WHERE 1=1";
$params = [];

if (!empty($filterDate)) {
    $sql .= " AND a.appointment_date = :adate";
    $params[':adate'] = $filterDate;
}
if ($filterService > 0) {
    $sql .= " AND a.service_id = :sid";
    $params[':sid'] = $filterService;
}
if (!empty($filterStatus)) {
    $sql .= " AND a.status = :st";
    $params[':st'] = $filterStatus;
}
if (!empty($search)) {
    $sql .= " AND (a.appointment_reference LIKE :s OR a.applicant_name LIKE :s OR a.ic_number LIKE :s OR a.phone LIKE :s)";
    $params[':s'] = "%{$search}%";
}

$sql .= " ORDER BY a.appointment_date DESC, a.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">Appointments Management</h4>
        </div>
        <a href="<?= BASE_URL ?>/customer/checkin.php" target="_blank" class="btn btn-primary btn-sm">
            <i class="bi bi-qr-code-scan me-1"></i> Open Check-in Kiosk
        </a>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <!-- Filter Card -->
        <div class="card border-0 shadow-sm rounded-4 bg-white p-3 mb-4">
            <form method="GET" action="<?= BASE_URL ?>/admin/appointments/index.php" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Search (Ref / Name / IC / Phone)</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Search keyword...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Date</label>
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Service</label>
                    <select name="service_id" class="form-select form-select-sm">
                        <option value="0">All Services</option>
                        <?php foreach ($services as $svc): ?>
                            <option value="<?= $svc['id'] ?>" <?= $filterService == $svc['id'] ? 'selected' : '' ?>>
                                <?= e($svc['service_code']) ?> - <?= e($svc['service_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="Booked" <?= $filterStatus === 'Booked' ? 'selected' : '' ?>>Booked</option>
                        <option value="Checked In" <?= $filterStatus === 'Checked In' ? 'selected' : '' ?>>Checked In</option>
                        <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-filter"></i> Filter
                    </button>
                    <a href="<?= BASE_URL ?>/admin/appointments/index.php" class="btn btn-light btn-sm border">Reset</a>
                </div>
            </form>
        </div>

        <!-- Appointments Table Card -->
        <div class="card border-0 shadow-sm rounded-4 bg-white">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">All Scheduled Appointments (<?= count($appointments) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th class="ps-3">Reference</th>
                                <th>Applicant Details</th>
                                <th>Service</th>
                                <th>Date & Time Slot</th>
                                <th>Linked Queue</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($appointments)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No appointments found matching current filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($appointments as $apt): ?>
                                    <tr>
                                        <td class="ps-3 font-monospace fw-bold text-primary">
                                            <?= e($apt['appointment_reference']) ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= e($apt['applicant_name']) ?></div>
                                            <div class="small text-muted">IC: <?= e($apt['ic_number']) ?> | Tel: <?= e($apt['phone']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border me-1"><?= e($apt['service_code']) ?></span>
                                            <?= e($apt['service_name']) ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= e($apt['appointment_date']) ?></div>
                                            <div class="small text-muted"><?= e($apt['time_slot']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($apt['queue_number'])): ?>
                                                <span class="badge bg-success font-monospace fs-6"><?= e($apt['queue_number']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Not Issued</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                                $badgeClass = match($apt['status']) {
                                                    'Booked' => 'bg-info',
                                                    'Checked In' => 'bg-success',
                                                    'Completed' => 'bg-secondary',
                                                    'Cancelled' => 'bg-danger',
                                                    default => 'bg-light text-dark'
                                                };
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= e($apt['status']) ?></span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if ($apt['status'] === 'Booked'): ?>
                                                <form method="POST" action="<?= BASE_URL ?>/admin/appointments/index.php" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="checkin">
                                                    <input type="hidden" name="appointment_id" value="<?= $apt['id'] ?>">
                                                    <button type="submit" class="btn btn-success btn-sm me-1" title="Manual Check-in & Issue Ticket">
                                                        <i class="bi bi-check2"></i> Check-in
                                                    </button>
                                                </form>
                                                <form method="POST" action="<?= BASE_URL ?>/admin/appointments/index.php" onsubmit="return confirm('Cancel this appointment?');" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="cancel">
                                                    <input type="hidden" name="appointment_id" value="<?= $apt['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Cancel</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
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
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
