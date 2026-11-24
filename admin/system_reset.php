<?php
// ============================================================
// Admin - System Reset / Daily Queue Initialization Tool
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/role_check.php';
require_role('Admin');

$pageTitle = 'System Reset Tool';
$pdo = Database::getConnection();
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $resetType = $_POST['reset_type'] ?? '';

    if ($resetType === 'today_queues') {
        $pdo->beginTransaction();
        try {
            // Delete calls for today's queues
            $pdo->exec("DELETE qc FROM `queue_calls` qc 
                        JOIN `queues` q ON qc.queue_id = q.id 
                        WHERE q.queue_date = '{$today}'");

            // Delete status history for today's queues
            $pdo->exec("DELETE qsh FROM `queue_status_history` qsh 
                        JOIN `queues` q ON qsh.queue_id = q.id 
                        WHERE q.queue_date = '{$today}'");

            // Delete transfers for today's queues
            $pdo->exec("DELETE qt FROM `queue_transfers` qt 
                        JOIN `queues` q ON qt.original_queue_id = q.id 
                        WHERE q.queue_date = '{$today}'");

            // Delete today's queues
            $delQueues = $pdo->exec("DELETE FROM `queues` WHERE `queue_date` = '{$today}'");

            // Reset sequence counters for today
            $pdo->exec("UPDATE `queue_sequences` SET `last_number` = 0 WHERE `queue_date` = '{$today}'");

            // Close all active counter sessions
            $pdo->exec("UPDATE `counter_sessions` SET `status` = 'closed', `ended_at` = NOW() WHERE `status` = 'active'");

            $pdo->commit();

            log_activity($_SESSION['user_id'], 'SYSTEM_RESET_TODAY', "Reset today's queues and sequences. Cleaned {$delQueues} tickets.");
            set_flash('success', "Daily queues successfully reset! Cleaned {$delQueues} tickets and reset sequence numbers to 0.");
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('danger', 'System reset failed: ' . $e->getMessage());
        }
    }

    header('Location: ' . BASE_URL . '/admin/system_reset.php');
    exit();
}

// Current system counters
$todayTickets = (int)$pdo->query("SELECT COUNT(*) FROM `queues` WHERE `queue_date` = '{$today}'")->fetchColumn();
$activeSessions = (int)$pdo->query("SELECT COUNT(*) FROM `counter_sessions` WHERE `status` = 'active'")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">System Reset & Daily Maintenance</h4>
        </div>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-circle me-3">
                            <i class="bi bi-arrow-counterclockwise fs-2"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-1">Reset Today's Queue Data</h4>
                            <p class="text-muted small mb-0">Clear test queue tickets, reset ticketing sequences, and prepare the service hall for a clean operating session.</p>
                        </div>
                    </div>

                    <div class="row g-3 my-3">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 border text-center">
                                <div class="fs-4 fw-bold text-dark"><?= $todayTickets ?></div>
                                <div class="small text-muted text-uppercase">Today's Tickets</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 border text-center">
                                <div class="fs-4 fw-bold text-primary"><?= $activeSessions ?></div>
                                <div class="small text-muted text-uppercase">Active Counter Sessions</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning d-flex align-items-start" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-warning"></i>
                        <div class="small">
                            <strong>Warning:</strong> This operation clears all queue calls, status logs, and tickets generated today (<strong><?= $today ?></strong>). 
                            Sequence numbers will start again from 001 for all service categories. Past days' historical analytics remain intact.
                        </div>
                    </div>

                    <form method="POST" action="<?= BASE_URL ?>/admin/system_reset.php" onsubmit="return confirm('Are you sure you want to reset today\'s queue data? This cannot be undone.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reset_type" value="today_queues">
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-danger btn-lg py-3 fw-bold rounded-3">
                                <i class="bi bi-trash3 me-1"></i> Confirm & Reset Today's Queue
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

    </main>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
