<?php
// ============================================================
// Staff - Complete Customer Service
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

    // 1. Verify Queue is currently in Serving status
    $stmt = $pdo->prepare("SELECT * FROM `queues` WHERE `id` = :qid AND `assigned_counter_id` = :cid FOR UPDATE");
    $stmt->execute([':qid' => $queueId, ':cid' => $counterId]);
    $queue = $stmt->fetch();

    if (!$queue) {
        throw new Exception("Queue record not found or not assigned to your counter.");
    }
    if ($queue['status'] !== 'Serving') {
        throw new Exception("Validation Error: A ticket can only be marked Completed when it is actively in 'Serving' status (Current: {$queue['status']}).");
    }

    // 2. Transition status from Serving to Completed
    $upd = $pdo->prepare("UPDATE `queues` 
                         SET `status` = 'Completed', 
                             `completed_time` = NOW() 
                         WHERE `id` = :qid AND `status` = 'Serving'");
    $upd->execute([':qid' => $queueId]);

    // 3. Record Audit
    record_queue_status_transition($pdo, $queueId, 'Serving', 'Completed', $staffId, "Service successfully concluded by staff");

    $pdo->commit();
    log_activity($staffId, 'QUEUE_COMPLETED', "Completed service for ticket {$queue['queue_number']}");
    set_flash('success', "Ticket {$queue['queue_number']} successfully completed.");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', $e->getMessage());
}

header('Location: ' . BASE_URL . '/staff/dashboard.php');
exit();
