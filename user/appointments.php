<?php
// ============================================================
// Citizen Portal - Online Appointment Booking & Management
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/role_check.php';
require_role('User');

$pageTitle = 'Appointments Management';
$pdo = Database::getConnection();
$userId = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

// Fetch user profile
$userStmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
$userStmt->execute([':id' => $userId]);
$currentUser = $userStmt->fetch();

// Active services
$services = $pdo->query("SELECT * FROM `services` WHERE `status` = 'active' ORDER BY `service_code` ASC")->fetchAll();

$error = '';
$selectedDate = trim($_GET['date'] ?? date('Y-m-d', strtotime('+1 day')));
$selectedService = (int)($_GET['service_id'] ?? ($services[0]['id'] ?? 1));

// Handle Cancellation
if (isset($_POST['action']) && $_POST['action'] === 'cancel') {
    require_csrf();
    $aptId = (int)($_POST['appointment_id'] ?? 0);
    $cancelStmt = $pdo->prepare("UPDATE `appointments` SET `status` = 'Cancelled' WHERE `id` = :id AND `user_id` = :uid AND `status` = 'Booked'");
    $cancelStmt->execute([':id' => $aptId, ':uid' => $userId]);
    if ($cancelStmt->rowCount() > 0) {
        log_activity($userId, 'APPOINTMENT_CANCELLED', "Appointment #{$aptId} cancelled by user");
        set_flash('success', 'Appointment successfully cancelled.');
    } else {
        set_flash('error', 'Unable to cancel appointment.');
    }
    header('Location: ' . BASE_URL . '/user/appointments.php');
    exit();
}

// Handle New Appointment Booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book') {
    require_csrf();
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $appointmentDate = trim($_POST['appointment_date'] ?? '');
    $timeSlot = trim($_POST['time_slot'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($serviceId <= 0 || empty($appointmentDate) || empty($timeSlot)) {
        $error = 'Please select a service, date, and valid time slot.';
    } elseif ($appointmentDate < $today) {
        $error = 'Appointment date cannot be in the past.';
    } else {
        $res = create_appointment([
            'user_id' => $userId,
            'service_id' => $serviceId,
            'applicant_name' => $currentUser['name'],
            'ic_number' => $currentUser['ic_number'] ?? 'N/A',
            'phone' => $currentUser['phone'] ?? 'N/A',
            'email' => $currentUser['email'] ?? null,
            'appointment_date' => $appointmentDate,
            'time_slot' => $timeSlot,
            'notes' => $notes
        ]);

        if ($res['success']) {
            set_flash('success', "Appointment booked successfully! Reference No: {$res['appointment_reference']}");
            header('Location: ' . BASE_URL . '/user/appointments.php');
            exit();
        } else {
            $error = 'Failed to book appointment. Please try again.';
        }
    }
}

// Get time slots for preview
$availableSlots = get_appointment_time_slots($selectedService, $selectedDate);

// Fetch user's appointments
$myAppointmentsStmt = $pdo->prepare("SELECT a.*, s.service_name, s.service_code 
                                     FROM `appointments` a 
                                     JOIN `services` s ON a.service_id = s.id 
                                     WHERE a.user_id = :uid 
                                     ORDER BY a.appointment_date DESC, a.id DESC");
$myAppointmentsStmt->execute([':uid' => $userId]);
$myAppointments = $myAppointmentsStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">Online Appointment Booking</h4>
        </div>
        <a href="<?= BASE_URL ?>/customer/checkin.php" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-qr-code-scan me-1"></i> Office Check-in Kiosk
        </a>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- Booking Form -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
                    <h5 class="fw-bold mb-3 d-flex align-items-center">
                        <i class="bi bi-calendar-plus text-primary me-2"></i> Book New Appointment
                    </h5>

                    <form method="POST" action="<?= BASE_URL ?>/user/appointments.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="book">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Applicant Name</label>
                            <input type="text" class="form-control bg-light" value="<?= e($currentUser['name']) ?>" readonly>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">IC / Passport</label>
                                <input type="text" class="form-control bg-light" value="<?= e($currentUser['ic_number'] ?? 'N/A') ?>" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="text" class="form-control bg-light" value="<?= e($currentUser['phone'] ?? 'N/A') ?>" readonly>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="service_id" class="form-label fw-semibold">Target Service <span class="text-danger">*</span></label>
                            <select name="service_id" id="service_id" class="form-select" onchange="refreshSlots()" required>
                                <?php foreach ($services as $svc): ?>
                                    <option value="<?= $svc['id'] ?>" <?= $selectedService == $svc['id'] ? 'selected' : '' ?>>
                                        <?= e($svc['service_code']) ?> - <?= e($svc['service_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="appointment_date" class="form-label fw-semibold">Appointment Date <span class="text-danger">*</span></label>
                            <input type="date" name="appointment_date" id="appointment_date" class="form-control" 
                                   min="<?= date('Y-m-d') ?>" value="<?= e($selectedDate) ?>" onchange="refreshSlots()" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Time Slot <span class="text-danger">*</span></label>
                            <div class="d-flex flex-column gap-2" style="max-height: 220px; overflow-y: auto;">
                                <?php foreach ($availableSlots as $slotInfo): ?>
                                    <div class="form-check p-2 border rounded-3 d-flex align-items-center justify-content-between <?= $slotInfo['is_available'] ? 'bg-light' : 'opacity-50 bg-secondary bg-opacity-10' ?>">
                                        <div>
                                            <input class="form-check-input ms-0 me-2" type="radio" name="time_slot" 
                                                   id="slot_<?= md5($slotInfo['slot']) ?>" 
                                                   value="<?= e($slotInfo['slot']) ?>" 
                                                   <?= $slotInfo['is_available'] ? '' : 'disabled' ?> required>
                                            <label class="form-check-label fw-semibold" for="slot_<?= md5($slotInfo['slot']) ?>">
                                                <?= e($slotInfo['slot']) ?>
                                            </label>
                                        </div>
                                        <span class="badge <?= $slotInfo['remaining'] > 3 ? 'bg-success' : ($slotInfo['remaining'] > 0 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                            <?= $slotInfo['remaining'] ?> slots left
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="notes" class="form-label fw-semibold">Special Notes / Reason (Optional)</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="e.g. Passport renewal urgency..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold rounded-3">
                            <i class="bi bi-check-circle-fill me-1"></i> Confirm Booking
                        </button>
                    </form>
                </div>
            </div>

            <!-- User's Appointments List -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 bg-white">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="fw-bold mb-0">My Scheduled Appointments</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th class="ps-3">Reference</th>
                                        <th>Service</th>
                                        <th>Date & Slot</th>
                                        <th>Status</th>
                                        <th class="text-end pe-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($myAppointments)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                No appointments booked yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($myAppointments as $apt): ?>
                                            <tr>
                                                <td class="ps-3 font-monospace fw-bold text-primary">
                                                    <?= e($apt['appointment_reference']) ?>
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
                                                    <?php
                                                        $badge = match($apt['status']) {
                                                            'Booked' => 'bg-info',
                                                            'Checked In' => 'bg-success',
                                                            'Completed' => 'bg-secondary',
                                                            'Cancelled' => 'bg-danger',
                                                            default => 'bg-light text-dark'
                                                        };
                                                    ?>
                                                    <span class="badge <?= $badge ?>"><?= e($apt['status']) ?></span>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <?php if ($apt['status'] === 'Booked'): ?>
                                                        <form method="POST" action="<?= BASE_URL ?>/user/appointments.php" onsubmit="return confirm('Are you sure you want to cancel this appointment?');" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="cancel">
                                                            <input type="hidden" name="appointment_id" value="<?= $apt['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">Cancel</button>
                                                        </form>
                                                    <?php elseif ($apt['status'] === 'Checked In' && !empty($apt['queue_id'])): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success">
                                                            <i class="bi bi-check2"></i> Checked In
                                                        </span>
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
            </div>
        </div>

    </main>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>

<script>
function refreshSlots() {
    const serviceId = document.getElementById('service_id').value;
    const dateVal = document.getElementById('appointment_date').value;
    if (serviceId && dateVal) {
        window.location.href = `<?= BASE_URL ?>/user/appointments.php?service_id=${serviceId}&date=${dateVal}`;
    }
}
</script>
