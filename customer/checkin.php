<?php
// ============================================================
// Customer Kiosk - Appointment Check-in & Ticket Conversion
// ============================================================

require_once __DIR__ . '/../config/config.php';

$bizName = get_setting('business_name', 'Smart City Service Center');
$error = '';
$searchKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $searchKey = trim($_POST['search_key'] ?? '');

    if (empty($searchKey)) {
        $error = 'Please enter your Appointment Reference Number or IC / Passport Number.';
    } else {
        $checkin = checkin_appointment($searchKey);
        if ($checkin['success']) {
            set_flash('success', "Appointment checked in successfully! Your queue number is {$checkin['queue_number']}.");
            header('Location: ' . BASE_URL . '/customer/ticket.php?token=' . urlencode($checkin['tracking_token']));
            exit();
        } else {
            $error = $checkin['message'] ?? 'Failed to check in appointment.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Check-in - <?= e($bizName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light min-vh-100 d-flex flex-column">

    <!-- Top Header -->
    <header class="bg-white border-bottom py-3 shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <i class="bi bi-qr-code-scan text-primary fs-2 me-2"></i>
                <div>
                    <h5 class="mb-0 fw-bold text-dark"><?= e($bizName) ?></h5>
                    <small class="text-muted">Office Check-in & Queue Ticket Kiosk</small>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-house-door me-1"></i> Home
            </a>
        </div>
    </header>

    <div class="container py-5 flex-grow-1" style="max-width: 640px;">
        
        <!-- Mode Switch Tabs -->
        <div class="d-flex justify-content-center mb-4">
            <div class="btn-group p-1 bg-white border rounded-pill shadow-sm" role="group">
                <a href="<?= BASE_URL ?>/customer/index.php" class="btn btn-outline-primary rounded-pill px-4 py-2">
                    <i class="bi bi-ticket-perforated me-1"></i> Walk-in Ticket
                </a>
                <a href="<?= BASE_URL ?>/customer/checkin.php" class="btn btn-primary rounded-pill px-4 py-2 active">
                    <i class="bi bi-calendar-check me-1"></i> Appointment Check-in
                </a>
            </div>
        </div>

        <div class="text-center mb-4">
            <h2 class="fw-bold text-dark mb-2">Office Check-in</h2>
            <p class="text-muted">Enter your appointment reference number or IC / Passport number to obtain your queue ticket.</p>
        </div>

        <?= display_flash() ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 rounded-4 p-4">
            <form method="POST" action="<?= BASE_URL ?>/customer/checkin.php">
                <?= csrf_field() ?>
                
                <div class="mb-4">
                    <label for="search_key" class="form-label fw-bold">
                        <i class="bi bi-search me-1 text-primary"></i> Appointment Reference or IC / Passport
                    </label>
                    <input type="text" name="search_key" id="search_key" 
                           class="form-control form-control-lg text-uppercase" 
                           placeholder="e.g. APT-20261124-ABCD or 950505-14-5555"
                           value="<?= e($searchKey) ?>" required autofocus>
                    <div class="form-text mt-2">
                        Appointments must be scheduled for today (<?= date('Y-m-d') ?>) and in Booked status.
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg py-3 fw-bold rounded-3">
                        <i class="bi bi-check-circle-fill me-2"></i> Verify & Print Queue Ticket
                    </button>
                    <a href="<?= BASE_URL ?>/customer/index.php" class="btn btn-light py-2 text-muted">
                        No appointment? Proceed as Walk-in Customer
                    </a>
                </div>
            </form>
        </div>

        <!-- Help Info Box -->
        <div class="card bg-white border rounded-4 p-3 mt-4 text-center">
            <div class="small text-muted">
                Need to schedule a new appointment? 
                <a href="<?= BASE_URL ?>/user/appointments.php" class="text-decoration-none fw-semibold">
                    Book Online via Citizen Portal <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>

    </div>

    <!-- Footer -->
    <footer class="bg-white border-top py-3 text-center text-muted small">
        <div class="container">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
