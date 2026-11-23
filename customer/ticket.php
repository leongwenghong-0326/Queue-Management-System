<?php
// ============================================================
// Customer - Digital Queue Ticket View & Print
// ============================================================

require_once __DIR__ . '/../config/config.php';

$rawToken = trim($_GET['token'] ?? '');
if (empty($rawToken)) {
    die("Error: Missing ticket tracking token.");
}

$tokenHash = hash_tracking_token($rawToken);
$pdo = Database::getConnection();

$stmt = $pdo->prepare("SELECT q.*, s.service_code, s.service_name, p.priority_name, c.customer_name 
                       FROM `queues` q 
                       JOIN `services` s ON q.service_id = s.id 
                       LEFT JOIN `queue_priorities` p ON q.priority_id = p.id 
                       LEFT JOIN `customers` c ON q.customer_id = c.id 
                       WHERE q.tracking_token_hash = :thash LIMIT 1");
$stmt->execute([':thash' => $tokenHash]);
$queue = $stmt->fetch();

if (!$queue) {
    die("Error: Invalid or expired ticket token.");
}

$bizName = get_setting('business_name', 'Smart City Service Center');
$bizPhone = get_setting('business_phone', '+60 3-8888 9999');
$bizAddress = get_setting('business_address', 'Main Customer Hall');

// Calculate detailed dynamic waiting metrics
$estimate = get_detailed_queue_estimate_data((int)$queue['service_id'], (int)$queue['id']);
$estimatedWait = $estimate['estimated_wait_minutes'];
$customersAhead = $estimate['customers_ahead'];
$estimateLabel = $estimate['estimate_label'];
$activeCountersCount = $estimate['active_counters_count'];

// Tracking URL for QR Code
$trackingUrl = BASE_URL . "/customer/track.php?token=" . urlencode($rawToken);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Ticket <?= e($queue['queue_number']) ?> - <?= e($bizName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <!-- QR Code Generator Script -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        .ticket-box {
            max-width: 420px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            border: 1px dashed #ced4da;
        }
    </style>
</head>
<body class="ticket-page bg-light min-vh-100 py-4 d-flex align-items-center">

    <div class="container">
        <div id="printableTicket" class="ticket-box p-4 text-center">
            
            <div class="mb-3">
                <i class="bi bi-building-fill-check text-primary fs-2"></i>
                <h5 class="fw-bold mb-0 text-dark"><?= e($bizName) ?></h5>
                <small class="text-muted"><?= e($bizAddress) ?></small>
            </div>

            <hr class="my-3">

            <div class="text-muted small text-uppercase fw-semibold mb-1">Your Queue Number</div>
            <div class="queue-number-display text-primary fw-bold my-2">
                <?= e($queue['queue_number']) ?>
            </div>

            <div class="badge bg-primary bg-opacity-10 text-primary fs-6 px-3 py-2 mb-3">
                <?= e($queue['service_name']) ?>
            </div>

            <div class="bg-light p-3 rounded-3 text-start small mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Issue Time:</span>
                    <span class="fw-semibold"><?= date('d M Y, h:i A', strtotime($queue['registration_time'])) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Status:</span>
                    <span class="badge bg-warning text-dark"><?= e($queue['status']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Customers Ahead:</span>
                    <span class="fw-bold text-dark"><?= $customersAhead ?></span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Active Counters:</span>
                    <span class="fw-semibold text-dark"><?= $activeCountersCount ?> stations open</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Estimated Wait Time:</span>
                    <span class="fw-bold text-primary"><?= e($estimateLabel) ?></span>
                </div>
                <div class="text-end text-muted fst-italic" style="font-size: 0.7rem;">
                    * Approximate estimate; actual start time depends on counter progress.
                </div>
            </div>

            <!-- Dynamic QR Code for Live Mobile Tracking -->
            <div class="my-3">
                <div id="qrcode" class="d-flex justify-content-center p-2 bg-white rounded"></div>
                <small class="text-muted d-block mt-2">Scan QR code to monitor live queue status on your phone</small>
            </div>

            <div class="text-muted small border-top pt-2 mt-3">
                Please listen for your queue number on the calling display.
            </div>

            <!-- Action Buttons (Hidden on Print) -->
            <div class="mt-4 no-print d-flex flex-column gap-2">
                <a href="<?= e($trackingUrl) ?>" class="btn btn-primary fw-semibold py-2">
                    <i class="bi bi-broadcast me-1"></i> Open Live Tracker On Screen
                </a>
                <button type="button" onclick="window.print()" class="btn btn-outline-dark fw-semibold">
                    <i class="bi bi-printer me-1"></i> Print Ticket
                </button>
                <a href="<?= BASE_URL ?>/customer/index.php" class="btn btn-link text-muted btn-sm text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i> Register Another Ticket
                </a>
            </div>

        </div>
    </div>

    <script>
        // Generate QR code pointing to secure tracking endpoint
        new QRCode(document.getElementById("qrcode"), {
            text: "<?= $trackingUrl ?>",
            width: 140,
            height: 140,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
    </script>
</body>
</html>
