<?php
// ============================================================
// Smart Queue Management System (QMS) - Main Portal Hub
// ============================================================

require_once __DIR__ . '/config/config.php';

$pdo = Database::getConnection();
$today = date('Y-m-d');

// Quick Stats for Hub
$waitingCount = (int)$pdo->query("SELECT COUNT(*) FROM `queues` WHERE `queue_date` = '{$today}' AND `status` = 'Waiting'")->fetchColumn();
$servingCount = (int)$pdo->query("SELECT COUNT(*) FROM `queues` WHERE `queue_date` = '{$today}' AND `status` = 'Serving'")->fetchColumn();
$completedCount = (int)$pdo->query("SELECT COUNT(*) FROM `queues` WHERE `queue_date` = '{$today}' AND `status` = 'Completed'")->fetchColumn();
$activeCountersCount = (int)$pdo->query("SELECT COUNT(*) FROM `counters` WHERE `status` = 'active'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> - Portal Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light min-vh-100 d-flex flex-column">

    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark py-3 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= BASE_URL ?>/index.php">
                <i class="bi bi-diagram-3-fill text-primary fs-3 me-2"></i>
                <span><?= e(APP_NAME) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <a href="<?= $_SESSION['role_name'] === 'Admin' ? BASE_URL . '/admin/dashboard.php' : BASE_URL . '/staff/dashboard.php' ?>" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-speedometer2 me-1"></i> My Workspace (<?= e($_SESSION['role_name']) ?>)
                    </a>
                    <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-danger btn-sm">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Staff / Admin Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero Header -->
    <div class="bg-white border-bottom py-5">
        <div class="container text-center">
            <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                Enterprise Multi-Counter Architecture
            </span>
            <h1 class="display-5 fw-bold text-dark mb-2">Smart Queue Management System</h1>
            <p class="lead text-muted mx-auto" style="max-width: 680px;">
                Complete queuing automation supporting self-service kiosk registration, real-time audio-visual call displays, atomic counter operations, and deep operational reporting.
            </p>

            <!-- Real-time Quick Stats Bar -->
            <div class="row g-3 justify-content-center mt-3" style="max-width: 800px; margin: 0 auto;">
                <div class="col-6 col-md-3">
                    <div class="p-3 bg-light rounded-3 border">
                        <div class="fs-4 fw-bold text-warning"><?= $waitingCount ?></div>
                        <div class="small text-muted text-uppercase fw-semibold">Waiting</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 bg-light rounded-3 border">
                        <div class="fs-4 fw-bold text-primary"><?= $servingCount ?></div>
                        <div class="small text-muted text-uppercase fw-semibold">Serving</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 bg-light rounded-3 border">
                        <div class="fs-4 fw-bold text-success"><?= $completedCount ?></div>
                        <div class="small text-muted text-uppercase fw-semibold">Completed</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 bg-light rounded-3 border">
                        <div class="fs-4 fw-bold text-info"><?= $activeCountersCount ?></div>
                        <div class="small text-muted text-uppercase fw-semibold">Counters</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Portal Navigation Cards -->
    <div class="container py-5 flex-grow-1">
        <div class="row g-4">
            
            <!-- 1. Customer Kiosk Portal -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 shadow-sm border-0 rounded-4 transition-hover text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="d-inline-flex p-3 bg-primary bg-opacity-10 text-primary rounded-circle mx-auto mb-3">
                            <i class="bi bi-ticket-perforated-fill fs-1"></i>
                        </div>
                        <h4 class="card-title fw-bold text-dark">Customer Kiosk</h4>
                        <p class="card-text text-muted small flex-grow-1">
                            Self-service registration portal for customers to select a service, take a digital ticket, and receive a secure tracking QR code.
                        </p>
                        <a href="<?= BASE_URL ?>/customer/index.php" class="btn btn-primary fw-semibold mt-3 py-2">
                            <i class="bi bi-plus-circle me-1"></i> Take Ticket
                        </a>
                    </div>
                </div>
            </div>

            <!-- 2. Public Display Monitor -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 shadow-sm border-0 rounded-4 transition-hover text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="d-inline-flex p-3 bg-success bg-opacity-10 text-success rounded-circle mx-auto mb-3">
                            <i class="bi bi-tv-fill fs-1"></i>
                        </div>
                        <h4 class="card-title fw-bold text-dark">Live Display Board</h4>
                        <p class="card-text text-muted small flex-grow-1">
                            High-contrast real-time calling board for waiting lobbies. Highlights calling counters with audio announcement chimes.
                        </p>
                        <a href="<?= BASE_URL ?>/display/index.php" target="_blank" class="btn btn-success fw-semibold mt-3 py-2">
                            <i class="bi bi-play-circle me-1"></i> Open Display
                        </a>
                    </div>
                </div>
            </div>

            <!-- 3. Staff Counter Terminal -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 shadow-sm border-0 rounded-4 transition-hover text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="d-inline-flex p-3 bg-warning bg-opacity-10 text-warning rounded-circle mx-auto mb-3">
                            <i class="bi bi-headset fs-1"></i>
                        </div>
                        <h4 class="card-title fw-bold text-dark">Staff Terminal</h4>
                        <p class="card-text text-muted small flex-grow-1">
                            Counter officers call the next queue, recall numbers, start serving, complete transactions, handle no-shows, or transfer queues.
                        </p>
                        <a href="<?= BASE_URL ?>/staff/dashboard.php" class="btn btn-warning fw-semibold mt-3 py-2 text-dark">
                            <i class="bi bi-cpu me-1"></i> Counter Console
                        </a>
                    </div>
                </div>
            </div>

            <!-- 4. Admin Management Center -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 shadow-sm border-0 rounded-4 transition-hover text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="d-inline-flex p-3 bg-dark bg-opacity-10 text-dark rounded-circle mx-auto mb-3">
                            <i class="bi bi-shield-lock-fill fs-1"></i>
                        </div>
                        <h4 class="card-title fw-bold text-dark">Admin Console</h4>
                        <p class="card-text text-muted small flex-grow-1">
                            Full management of staff, counters, services, operating schedule, real-time queue monitors, and analytical export reports.
                        </p>
                        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-dark fw-semibold mt-3 py-2">
                            <i class="bi bi-gear-wide-connected me-1"></i> Admin Dashboard
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-top py-4 text-center text-muted small">
        <div class="container">
            <p class="mb-1">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Fully functional transactional queue system.</p>
            <p class="mb-0 text-muted">Powered by PHP 8.2, MySQL InnoDB, Bootstrap 5 & Real-time AJAX Engine</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
