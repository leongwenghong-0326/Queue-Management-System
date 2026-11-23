<?php
// ============================================================
// Staff - Start Serving Customer
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_csrf();

$staffId = (int)$_SESSION['user_id'];
$queueId = (int)($_POST['queue_id'] ?? 0);
$counterId = (int)($_POST['counter_id'] ?? ($_SESSION['active_counter_id'] ?? 0));

if ($queueId <= 0 || $counterId <= 0) {
    set_flash('danger', 'Invalid request parameters.');
    header('Location: ' . BASE_URL . '/staff/dashboard.php');
    exit();
}

$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();

    // 1. Verify Queue is currently Called at this counter
    $stmt = $pdo->prepare("SELECT * FROM `queues` WHERE `id` = :qid AND `assigned_counter_id` = :cid FOR UPDATE");
    $stmt->execute([':qid' => $queueId, ':cid' => $counterId]);
    $queue = $stmt->fetch();

    if (!$queue) {
        throw new Exception("Queue record not found or not assigned to your counter.");
    }
    if ($queue['status'] !== 'Called') {
        throw new Exception("Cannot start serving: Ticket must be in 'Called' status first (Current: {$queue['status']}).");
    }

    // 2. Transition status from Called to Serving
    $upd = $pdo->prepare("UPDATE `queues` 
                         SET `status` = 'Serving', 
                             `service_start_time` = NOW(), 
                             `assigned_staff_id` = :sid 
                         WHERE `id` = :qid AND `status` = 'Called'");
    $upd->execute([':sid' => $staffId, ':qid' => $queueId]);

    // 3. Record Audit
    record_queue_status_transition($pdo, $queueId, 'Called', 'Serving', $staffId, "Staff initiated customer service session");

    $pdo->commit();
    log_activity($staffId, 'QUEUE_STARTED_SERVING', "Started serving ticket {$queue['queue_number']}");
    set_flash('success', "Service started for customer {$queue['queue_number']}.");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', $e->getMessage());
}

header('Location: ' . BASE_URL . '/staff/dashboard.php');
exit();
