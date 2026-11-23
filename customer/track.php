<?php
// ============================================================
// Customer - Real-Time Mobile Queue Tracker (Secure Token)
// ============================================================

require_once __DIR__ . '/../config/config.php';

$rawToken = trim($_GET['token'] ?? '');
if (empty($rawToken)) {
    die("Security Error: Tracking token is missing.");
}

$tokenHash = hash_tracking_token($rawToken);
$pdo = Database::getConnection();

$stmt = $pdo->prepare("SELECT q.*, s.service_name, s.service_code, cnt.counter_name, cnt.location as counter_location 
                       FROM `queues` q 
                       JOIN `services` s ON q.service_id = s.id 
                       LEFT JOIN `counters` cnt ON q.assigned_counter_id = cnt.id 
                       WHERE q.tracking_token_hash = :thash LIMIT 1");
$stmt->execute([':thash' => $tokenHash]);
$queue = $stmt->fetch();

if (!$queue) {
    die("Security Error: Invalid or expired tracking token.");
}

$bizName = get_setting('business_name', 'Smart City Service Center');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking <?= e($queue['queue_number']) ?> - <?= e($bizName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        .tracker-card {
            max-width: 480px;
            margin: 0 auto;
        }
        .status-hero {
            padding: 2.5rem 1.5rem;
            border-radius: 16px;
            text-align: center;
            transition: all 0.3s ease;
        }
    </style>
</head>
<body class="bg-light min-vh-100 py-4">

    <div class="container tracker-card">
        <!-- Brand Header -->
        <div class="text-center mb-3">
            <h5 class="fw-bold text-dark mb-0"><?= e($bizName) ?></h5>
            <small class="text-muted">Live Queue Mobile Tracker</small>
        </div>

        <!-- Main Real-Time Status Card -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-3">
            <div id="statusContainer" class="status-hero bg-primary text-white">
                <span class="badge bg-white text-dark px-3 py-1 rounded-pill fw-semibold mb-2" id="serviceLabel">
                    <?= e($queue['service_name']) ?>
                </span>
                <div class="queue-number-display fw-bold mb-1" id="queueNumberDisplay">
                    <?= e($queue['queue_number']) ?>
                </div>
                <h4 class="fw-bold mb-2" id="statusTitle">Waiting in Queue</h4>
                <p class="mb-0 opacity-75 small" id="statusSubtitle">
                    Your ticket is registered. Please stay close to the waiting lobby.
                </p>
            </div>

            <!-- Live Calling Notification Banner (Hidden by default, shown when Called) -->
            <div id="calledBanner" class="p-3 bg-warning text-dark fw-bold text-center call-blink d-none">
                <i class="bi bi-bell-fill fs-4 me-2"></i>
                <span id="calledMessage" class="fs-5">Please proceed to Counter now!</span>
            </div>

            <div class="card-body p-4">
                <div class="row g-2 text-center">
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted small text-uppercase fw-semibold">Customers Ahead</div>
                            <div class="fs-2 fw-bold text-dark" id="aheadCountDisplay">--</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted small text-uppercase fw-semibold">Est. Wait Time</div>
                            <div class="fs-2 fw-bold text-primary" id="waitTimeDisplay">--</div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 p-3 bg-light rounded-3 small">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Registered At:</span>
                        <span class="fw-semibold"><?= date('h:i A', strtotime($queue['registration_time'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Assigned Station:</span>
                        <span class="fw-semibold text-primary" id="counterStationDisplay">
                            <?= e($queue['counter_name'] ?? 'Pending Call') ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Last Updated:</span>
                        <span class="text-muted" id="lastUpdatedClock"><?= date('h:i:s A') ?></span>
                    </div>
                </div>
                <div class="text-center text-muted fst-italic mt-2" style="font-size: 0.72rem;">
                    * Dynamic estimated waiting time updates automatically as counters progress.
                </div>
            </div>
        </div>

        <!-- Audio notification for customer when called -->
        <audio id="chimeSound" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" preload="auto"></audio>

        <div class="text-center">
            <button class="btn btn-outline-secondary btn-sm" onclick="fetchStatus()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh Now
            </button>
            <div class="small text-muted mt-2">
                <i class="bi bi-shield-lock-fill text-success me-1"></i> Secured with encrypted session token
            </div>
        </div>
    </div>

    <!-- AJAX Polling Script -->
    <script>
        const token = "<?= urlencode($rawToken) ?>";
        let hasChimed = false;

        async function fetchStatus() {
            try {
                const res = await fetch(`<?= BASE_URL ?>/api/queue_status.php?token=${token}`);
                if (!res.ok) return;
                const data = await res.json();

                if (data.success && data.queue) {
                    const q = data.queue;
                    document.getElementById('aheadCountDisplay').textContent = data.customers_ahead;
                    if (q.status === 'Called' || q.status === 'Serving') {
                        document.getElementById('waitTimeDisplay').textContent = '0m';
                    } else if (data.is_next_in_line) {
                        document.getElementById('waitTimeDisplay').textContent = '< 1m';
                    } else {
                        document.getElementById('waitTimeDisplay').textContent = `~${data.estimated_wait_minutes}m`;
                    }
                    document.getElementById('lastUpdatedClock').textContent = new Date().toLocaleTimeString();

                    const container = document.getElementById('statusContainer');
                    const title = document.getElementById('statusTitle');
                    const subtitle = document.getElementById('statusSubtitle');
                    const calledBanner = document.getElementById('calledBanner');
                    const counterDisplay = document.getElementById('counterStationDisplay');

                    if (q.status === 'Called') {
                        container.className = 'status-hero bg-warning text-dark';
                        title.textContent = 'YOU ARE BEING CALLED!';
                        subtitle.textContent = `Please proceed immediately to ${q.counter_name || 'Assigned Counter'}`;
                        calledBanner.classList.remove('d-none');
                        document.getElementById('calledMessage').textContent = `Please proceed to ${q.counter_name || 'your counter'}!`;
                        counterDisplay.textContent = q.counter_name || 'Calling Counter';

                        if (!hasChimed) {
                            try {
                                document.getElementById('chimeSound').play();
                            } catch(e) {}
                            hasChimed = true;
                        }
                    } else if (q.status === 'Serving') {
                        container.className = 'status-hero bg-success text-white';
                        title.textContent = 'Currently Being Served';
                        subtitle.textContent = `You are being attended at ${q.counter_name || 'Counter'}`;
                        calledBanner.classList.add('d-none');
                        counterDisplay.textContent = q.counter_name || 'Active Counter';
                    } else if (q.status === 'Completed') {
                        container.className = 'status-hero bg-secondary text-white';
                        title.textContent = 'Service Completed';
                        subtitle.textContent = 'Thank you for visiting! Have a wonderful day.';
                        calledBanner.classList.add('d-none');
                    } else if (q.status === 'No-Show') {
                        container.className = 'status-hero bg-danger text-white';
                        title.textContent = 'Marked as No-Show';
                        subtitle.textContent = 'Your number was called but you were not present. Please register for a new ticket.';
                        calledBanner.classList.add('d-none');
                    } else if (q.status === 'Cancelled') {
                        container.className = 'status-hero bg-dark text-white';
                        title.textContent = 'Queue Cancelled';
                        subtitle.textContent = 'This ticket has been cancelled.';
                        calledBanner.classList.add('d-none');
                    } else {
                        // Waiting
                        container.className = 'status-hero bg-primary text-white';
                        title.textContent = 'Waiting in Queue';
                        subtitle.textContent = 'Your ticket is registered. Please stay close to the waiting lobby.';
                        calledBanner.classList.add('d-none');
                        counterDisplay.textContent = 'Pending Call';
                    }
                }
            } catch (err) {
                console.error("Polling error: ", err);
            }
        }

        // Poll every 3 seconds
        setInterval(fetchStatus, 3000);
        fetchStatus();
    </script>
</body>
</html>
