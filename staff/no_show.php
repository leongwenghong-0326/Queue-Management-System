<?php
// ============================================================
// Staff - Mark Customer as No-Show
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_csrf();

$staffId = (int)$_SESSION['user_id'];
$queueId = (int)($_POST['queue_id'] ?? 0);
$counterId = (int)($_POST['counter_id'] ?? ($_SESSION['active_counter_id'] ?? 0));
$reason = trim($_POST['reason'] ?? 'Customer did not show up after repeated calls');

if ($queueId <= 0 || $counterId <= 0) {
    set_flash('danger', 'Invalid request parameters.');
    header('Location: ' . BASE_URL . '/staff/dashboard.php');
    exit();
}

$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();

    // 1. Verify Queue is in Called status
    $stmt = $pdo->prepare("SELECT * FROM `queues` WHERE `id` = :qid AND `assigned_counter_id` = :cid FOR UPDATE");
    $stmt->execute([':qid' => $queueId, ':cid' => $counterId]);
    $queue = $stmt->fetch();

    if (!$queue) {
        throw new Exception("Queue record not found or not assigned to your counter.");
    }
    if ($queue['status'] !== 'Called') {
        throw new Exception("Policy Violation: Only tickets currently in 'Called' state can be marked as No-Show (Current: {$queue['status']}).");
    }

    // 2. Transition status from Called to No-Show
    $upd = $pdo->prepare("UPDATE `queues` 
                         SET `status` = 'No-Show' 
                         WHERE `id` = :qid AND `status` = 'Called'");
    $upd->execute([':qid' => $queueId]);

    // 3. Record Audit
    record_queue_status_transition($pdo, $queueId, 'Called', 'No-Show', $staffId, $reason);

    $pdo->commit();
    log_activity($staffId, 'QUEUE_MARKED_NOSHOW', "Marked ticket {$queue['queue_number']} as No-Show. Reason: {$reason}");
    set_flash('warning', "Ticket {$queue['queue_number']} marked as No-Show.");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', $e->getMessage());
}

header('Location: ' . BASE_URL . '/staff/dashboard.php');
exit();
