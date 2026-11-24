<?php
// ============================================================
// Customer Kiosk - Self-Service Queue Registration
// ============================================================

require_once __DIR__ . '/../config/config.php';

$pdo = Database::getConnection();
$today = date('Y-m-d');

// Fetch all active services
$services = $pdo->query("SELECT s.*, 
    (SELECT COUNT(*) FROM `queues` q WHERE q.service_id = s.id AND q.queue_date = CURDATE() AND q.status = 'Waiting') AS waiting_count,
    (SELECT COUNT(*) FROM `queues` q WHERE q.service_id = s.id AND q.queue_date = CURDATE() AND q.status IN ('Called', 'Serving')) AS in_service_count
FROM `services` s 
WHERE s.status = 'active' 
ORDER BY s.service_code ASC")->fetchAll();

// Fetch active priorities
$priorities = $pdo->query("SELECT * FROM `queue_priorities` WHERE `status` = 'active' ORDER BY priority_level ASC")->fetchAll();

$bizName = get_setting('business_name', 'Smart City Service Center');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take a Queue Ticket - <?= e($bizName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        .service-card {
            cursor: pointer;
            transition: all 0.25s ease;
            border: 2px solid transparent;
        }
        .service-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.12) !important;
            border-color: #0d6efd;
        }
        .service-card.selected {
            border-color: #0d6efd;
            background-color: #f0f7ff;
        }
    </style>
</head>
<body class="bg-light min-vh-100 d-flex flex-column">

    <!-- Top Header -->
    <header class="bg-white border-bottom py-3 shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <i class="bi bi-ticket-perforated-fill text-primary fs-2 me-2"></i>
                <div>
                    <h5 class="mb-0 fw-bold text-dark"><?= e($bizName) ?></h5>
                    <small class="text-muted">Self-Service Queue Ticket Kiosk</small>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-house-door me-1"></i> Home
            </a>
        </div>
    </header>

    <div class="container py-5 flex-grow-1" style="max-width: 900px;">
        <!-- Mode Switch Tabs -->
        <div class="d-flex justify-content-center mb-4">
            <div class="btn-group p-1 bg-white border rounded-pill shadow-sm" role="group">
                <a href="<?= BASE_URL ?>/customer/index.php" class="btn btn-primary rounded-pill px-4 py-2 active">
                    <i class="bi bi-ticket-perforated me-1"></i> Walk-in Ticket
                </a>
                <a href="<?= BASE_URL ?>/customer/checkin.php" class="btn btn-outline-primary rounded-pill px-4 py-2">
                    <i class="bi bi-calendar-check me-1"></i> Appointment Check-in
                </a>
            </div>
        </div>

        <div class="text-center mb-4">
            <h2 class="fw-bold text-dark mb-2">Welcome! Please Select a Service</h2>
            <p class="text-muted">Touch or click on your desired service below to obtain your queue ticket number.</p>
        </div>

        <?= display_flash() ?>

        <form id="queueRegisterForm" method="POST" action="<?= BASE_URL ?>/customer/register.php">
            <?= csrf_field() ?>
            <input type="hidden" name="service_id" id="selectedServiceId" value="" required>

            <!-- Service Selection Grid -->
            <div class="row g-3 mb-4">
                <?php if (empty($services)): ?>
                    <div class="col-12 text-center py-5">
                        <div class="alert alert-warning">No services are currently active. Please speak to our front desk officer.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($services as $svc): ?>
                        <?php 
                            $estWait = calculate_estimated_waiting_time((int)$svc['id']);
                        ?>
                        <div class="col-md-6">
                            <div class="card service-card h-100 shadow-sm rounded-4 p-3" onclick="selectService(<?= $svc['id'] ?>, this)">
                                <div class="d-flex align-items-start justify-content-between mb-2">
                                    <span class="badge bg-primary fs-5 px-3 py-2 rounded-3"><?= e($svc['service_code']) ?></span>
                                    <?php $totalActiveQueue = (int)$svc['waiting_count'] + (int)$svc['in_service_count']; ?>
                                    <?php if ($totalActiveQueue > 0): ?>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-people me-1"></i> <?= $totalActiveQueue ?> in queue <?= $svc['in_service_count'] > 0 ? '(' . $svc['in_service_count'] . ' serving)' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                                            <i class="bi bi-check2-circle me-1"></i> Available Now
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h5 class="fw-bold text-dark mb-1"><?= e($svc['service_name']) ?></h5>
                                <p class="text-muted small mb-3 flex-grow-1"><?= e($svc['description']) ?></p>
                                <div class="d-flex justify-content-between align-items-center pt-2 border-top text-muted small">
                                    <span><i class="bi bi-clock-history me-1"></i> <?= $totalActiveQueue > 0 ? 'Est. Wait' : 'Est. Time' ?>: <strong class="text-primary">~<?= $estWait ?> mins</strong></span>
                                    <span class="text-primary fw-semibold"><i class="bi bi-check2-circle"></i> Select</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Customer Details Card (Optional) -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="bi bi-person-lines-fill text-primary me-2"></i>Customer Information (Optional)
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Your Name</label>
                            <input type="text" name="customer_name" class="form-control" placeholder="e.g. Alex Wong (optional)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Phone</label>
                            <input type="tel" name="phone" class="form-control" placeholder="e.g. 012-3456789 (for SMS alerts)">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label small fw-semibold">Priority Category</label>
                        <select name="priority_id" class="form-select">
                            <?php foreach ($priorities as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $p['priority_level'] == 1 ? 'selected' : '' ?>>
                                    <?= e($p['priority_name']) ?> <?= $p['priority_level'] > 1 ? ' (' . e($p['description']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Priority queues are subject to staff verification at the counter.</small>
                    </div>
                </div>
            </div>

            <!-- Submit Button (With double-click prevention) -->
            <div class="text-center">
                <button type="submit" id="submitBtn" class="btn btn-primary btn-lg px-5 py-3 fw-bold rounded-pill shadow" disabled>
                    <i class="bi bi-printer me-2"></i> Get My Queue Ticket
                </button>
                <div id="btnHint" class="text-muted small mt-2">Please tap or select a service above to activate ticket generation.</div>
            </div>
        </form>
    </div>

    <script>
        function selectService(id, element) {
            document.querySelectorAll('.service-card').forEach(c => c.classList.remove('selected'));
            element.classList.add('selected');
            document.getElementById('selectedServiceId').value = id;
            
            const btn = document.getElementById('submitBtn');
            btn.disabled = false;
            document.getElementById('btnHint').textContent = 'Service selected! Click above to generate your ticket.';
        }

        // Prevent duplicate submissions on double-click
        document.getElementById('queueRegisterForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('submitBtn');
            if (btn.disabled) {
                e.preventDefault();
                return false;
            }
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Generating Your Ticket...';
        });
    </script>
</body>
</html>
