<?php
// ============================================================
// Citizen Portal - User Dashboard
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/role_check.php';
require_role('User');

$pageTitle = 'Citizen Dashboard';
$pdo = Database::getConnection();
$userId = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

// User profile details
$userStmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
$userStmt->execute([':id' => $userId]);
$currentUser = $userStmt->fetch();

// Quick statistics
$appointmentsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `appointments` WHERE `user_id` = :uid AND `status` != 'Cancelled'");
$appointmentsCountStmt->execute([':uid' => $userId]);
$totalAppointments = (int)$appointmentsCountStmt->fetchColumn();

$upcomingStmt = $pdo->prepare("SELECT COUNT(*) FROM `appointments` WHERE `user_id` = :uid AND `appointment_date` >= :today AND `status` = 'Booked'");
$upcomingStmt->execute([':uid' => $userId, ':today' => $today]);
$upcomingAppointments = (int)$upcomingStmt->fetchColumn();

$applicationsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `applications` WHERE `user_id` = :uid");
$applicationsCountStmt->execute([':uid' => $userId]);
$totalApplications = (int)$applicationsCountStmt->fetchColumn();

$pendingAppStmt = $pdo->prepare("SELECT COUNT(*) FROM `applications` WHERE `user_id` = :uid AND `status` = 'Pending'");
$pendingAppStmt->execute([':uid' => $userId]);
$pendingApplications = (int)$pendingAppStmt->fetchColumn();

// Recent appointments
$recentAptStmt = $pdo->prepare("SELECT a.*, s.service_name, s.service_code 
                                FROM `appointments` a 
                                JOIN `services` s ON a.service_id = s.id 
                                WHERE a.user_id = :uid 
                                ORDER BY a.appointment_date DESC, a.id DESC LIMIT 5");
$recentAptStmt->execute([':uid' => $userId]);
$recentAppointments = $recentAptStmt->fetchAll();

// Recent applications
$recentAppStmt = $pdo->prepare("SELECT a.*, s.service_name 
                                FROM `applications` a 
                                JOIN `services` s ON a.service_id = s.id 
                                WHERE a.user_id = :uid 
                                ORDER BY a.id DESC LIMIT 5");
$recentAppStmt->execute([':uid' => $userId]);
$recentApplications = $recentAppStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">Citizen Portal</h4>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary px-3 py-2 rounded-pill">
                <i class="bi bi-person-circle me-1"></i> <?= e($currentUser['name']) ?>
            </span>
        </div>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <!-- Welcome Banner -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-primary text-white p-4">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <h3 class="fw-bold mb-1">Welcome back, <?= e($currentUser['name']) ?>!</h3>
                    <p class="mb-0 opacity-75">
                        Manage your service appointments, track real-time queue tickets, and view official online application statuses.
                    </p>
                </div>
                <div class="col-lg-5 mt-3 mt-lg-0 d-flex align-items-center justify-content-lg-end gap-2 flex-wrap flex-sm-nowrap">
                    <a href="<?= BASE_URL ?>/user/appointments.php" class="btn btn-light fw-bold px-3 py-2 rounded-3 text-nowrap shadow-sm">
                        <i class="bi bi-calendar-plus me-1"></i> Book Appointment
                    </a>
                    <a href="<?= BASE_URL ?>/user/applications.php" class="btn btn-outline-light fw-bold px-3 py-2 rounded-3 text-nowrap">
                        <i class="bi bi-file-earmark-plus me-1"></i> Apply Online
                    </a>
                </div>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Upcoming Visits</div>
                            <div class="fs-2 fw-bold text-primary mt-1"><?= $upcomingAppointments ?></div>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-circle">
                            <i class="bi bi-calendar-event fs-3"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Total Bookings</div>
                            <div class="fs-2 fw-bold text-dark mt-1"><?= $totalAppointments ?></div>
                        </div>
                        <div class="bg-secondary bg-opacity-10 text-secondary p-3 rounded-circle">
                            <i class="bi bi-calendar-check fs-3"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Pending Reviews</div>
                            <div class="fs-2 fw-bold text-warning mt-1"><?= $pendingApplications ?></div>
                        </div>
                        <div class="bg-warning bg-opacity-10 text-warning p-3 rounded-circle">
                            <i class="bi bi-hourglass-split fs-3"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Total Applications</div>
                            <div class="fs-2 fw-bold text-success mt-1"><?= $totalApplications ?></div>
                        </div>
                        <div class="bg-success bg-opacity-10 text-success p-3 rounded-circle">
                            <i class="bi bi-file-earmark-check fs-3"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Recent Appointments Table -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">My Recent Appointments</h5>
                        <a href="<?= BASE_URL ?>/user/appointments.php" class="btn btn-sm btn-outline-primary">View All</a>
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
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentAppointments)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                No appointments booked yet. <a href="<?= BASE_URL ?>/user/appointments.php">Book now</a>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentAppointments as $apt): ?>
                                            <tr>
                                                <td class="ps-3 fw-bold text-primary font-monospace"><?= e($apt['appointment_reference']) ?></td>
                                                <td><?= e($apt['service_name']) ?></td>
                                                <td>
                                                    <div class="fw-semibold"><?= e($apt['appointment_date']) ?></div>
                                                    <div class="small text-muted"><?= e($apt['time_slot']) ?></div>
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
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Applications Table -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">My Online Applications</h5>
                        <a href="<?= BASE_URL ?>/user/applications.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th class="ps-3">App Reference</th>
                                        <th>Service</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentApplications)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                No applications submitted yet. <a href="<?= BASE_URL ?>/user/applications.php">Submit one</a>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentApplications as $app): ?>
                                            <tr>
                                                <td class="ps-3 fw-bold text-dark font-monospace"><?= e($app['application_reference']) ?></td>
                                                <td><?= e($app['service_name']) ?></td>
                                                <td><?= date('Y-m-d', strtotime($app['created_at'])) ?></td>
                                                <td>
                                                    <?php
                                                        $appBadge = match($app['status']) {
                                                            'Pending' => 'bg-warning text-dark',
                                                            'Approved' => 'bg-success',
                                                            'Rejected' => 'bg-danger',
                                                            default => 'bg-light text-dark'
                                                        };
                                                    ?>
                                                    <span class="badge <?= $appBadge ?>"><?= e($app['status']) ?></span>
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
