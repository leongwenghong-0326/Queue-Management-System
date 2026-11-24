<?php
// ============================================================
// Staff - Appointments View & Desk Check-in
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/role_check.php';
require_role('Staff');

$pageTitle = 'Appointments List';
$pdo = Database::getConnection();
$today = date('Y-m-d');

// Handle Check-in from staff desk
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $aptId = (int)($_POST['appointment_id'] ?? 0);
    if ($aptId > 0) {
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
    }
    header('Location: ' . BASE_URL . '/staff/appointments.php');
    exit();
}

// Fetch today's appointments
$stmt = $pdo->prepare("SELECT a.*, s.service_code, s.service_name, q.queue_number 
                       FROM `appointments` a 
                       JOIN `services` s ON a.service_id = s.id 
                       LEFT JOIN `queues` q ON a.queue_id = q.id 
                       WHERE a.appointment_date = :today 
                       ORDER BY a.time_slot ASC, a.id ASC");
$stmt->execute([':today' => $today]);
$appointments = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">Today's Scheduled Appointments</h4>
        </div>
        <a href="<?= BASE_URL ?>/staff/dashboard.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Return to Calling Console
        </a>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <div class="card border-0 shadow-sm rounded-4 bg-white">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">Appointments Scheduled for Today (<?= date('Y-m-d') ?>)</h5>
                <span class="badge bg-primary px-3 py-2 rounded-pill"><?= count($appointments) ?> booked</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th class="ps-3">Time Slot</th>
                                <th>Reference</th>
                                <th>Applicant</th>
                                <th>Service</th>
                                <th>Queue Number</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($appointments)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No appointments scheduled for today.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($appointments as $apt): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-dark"><?= e($apt['time_slot']) ?></td>
                                        <td class="font-monospace fw-bold text-primary"><?= e($apt['appointment_reference']) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= e($apt['applicant_name']) ?></div>
                                            <div class="small text-muted">IC: <?= e($apt['ic_number']) ?> | Tel: <?= e($apt['phone']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border me-1"><?= e($apt['service_code']) ?></span>
                                            <?= e($apt['service_name']) ?>
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
                                                <form method="POST" action="<?= BASE_URL ?>/staff/appointments.php" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="appointment_id" value="<?= $apt['id'] ?>">
                                                    <button type="submit" class="btn btn-success btn-sm">
                                                        <i class="bi bi-check2"></i> Check-in Customer
                                                    </button>
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

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
