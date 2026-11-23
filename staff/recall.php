<?php
// ============================================================
// Staff - Recall Customer
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_csrf();

$staffId = (int)$_SESSION['user_id'];
$queueId = (int)($_POST['queue_id'] ?? 0);
$counterId = (int)($_POST['counter_id'] ?? ($_SESSION['active_counter_id'] ?? 0));

if ($queueId <= 0 || $counterId <= 0) {
    set_flash('danger', 'Invalid queue or counter parameter.');
    header('Location: ' . BASE_URL . '/staff/dashboard.php');
    exit();
}

$pdo = Database::getConnection();

try {
    // 1. Verify Queue is in Called status
    $stmt = $pdo->prepare("SELECT * FROM `queues` WHERE `id` = :qid AND `status` = 'Called' AND `assigned_counter_id` = :cid LIMIT 1");
    $stmt->execute([':qid' => $queueId, ':cid' => $counterId]);
    $queue = $stmt->fetch();

    if (!$queue) {
        throw new Exception("Recall can only be performed on a ticket currently in 'Called' status at your counter.");
    }

    // 2. Check Recall limits & cooldown
    $maxRecalls = (int)get_setting('max_recall_attempts', '3');
    $cooldownSec = (int)get_setting('recall_wait_seconds', '30');

    $callCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `queue_calls` WHERE `queue_id` = :qid AND `call_type` = 'Recall'");
    $callCountStmt->execute([':qid' => $queueId]);
    $currentRecalls = (int)$callCountStmt->fetchColumn();

    if ($currentRecalls >= $maxRecalls) {
        throw new Exception("Maximum recall limit ({$maxRecalls} attempts) reached for ticket {$queue['queue_number']}. Please proceed to Start Serving or Mark No-Show.");
    }

    // Check last call timestamp
    $lastCallStmt = $pdo->prepare("SELECT called_at FROM `queue_calls` WHERE `queue_id` = :qid ORDER BY id DESC LIMIT 1");
    $lastCallStmt->execute([':qid' => $queueId]);
    $lastCallTime = $lastCallStmt->fetchColumn();

    if ($lastCallTime) {
        $elapsed = time() - strtotime($lastCallTime);
        if ($elapsed < $cooldownSec) {
            $remaining = $cooldownSec - $elapsed;
            throw new Exception("Recall cooldown active. Please wait {$remaining} more second(s) before recalling.");
        }
    }

    // 3. Record Recall
    $callStmt = $pdo->prepare("INSERT INTO `queue_calls` (`queue_id`, `counter_id`, `staff_id`, `call_type`, `called_at`) 
                               VALUES (:qid, :cid, :sid, 'Recall', NOW())");
    $callStmt->execute([
        ':qid' => $queueId,
        ':cid' => $counterId,
        ':sid' => $staffId
    ]);

    log_activity($staffId, 'QUEUE_RECALLED', "Recalled ticket {$queue['queue_number']} (Attempt #" . ($currentRecalls + 1) . ")");
    set_flash('success', "Ticket {$queue['queue_number']} recalled successfully (Attempt " . ($currentRecalls + 1) . "/{$maxRecalls}).");

} catch (Exception $e) {
    set_flash('warning', $e->getMessage());
}

header('Location: ' . BASE_URL . '/staff/dashboard.php');
exit();
