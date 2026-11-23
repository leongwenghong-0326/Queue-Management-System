<?php
// ============================================================
// Staff - Call Next Customer (Atomic Row-Locked Operation)
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_csrf();

$staffId = (int)$_SESSION['user_id'];
$counterId = (int)($_POST['counter_id'] ?? ($_SESSION['active_counter_id'] ?? 0));

if ($counterId <= 0) {
    set_flash('danger', 'Please select and activate a service counter before calling customers.');
    header('Location: ' . BASE_URL . '/staff/dashboard.php');
    exit();
}

$pdo = Database::getConnection();
$today = date('Y-m-d');

try {
    $pdo->beginTransaction();

    // 1. Verify Counter is Active
    $cntStmt = $pdo->prepare("SELECT * FROM `counters` WHERE `id` = :cid AND `status` = 'active' LIMIT 1");
    $cntStmt->execute([':cid' => $counterId]);
    $counter = $cntStmt->fetch();
    if (!$counter) {
        throw new Exception("The selected counter is currently inactive or offline.");
    }

    // 2. Verify Counter does not already have an active customer in Called or Serving state
    $busyStmt = $pdo->prepare("SELECT id, queue_number, status FROM `queues` 
                              WHERE `assigned_counter_id` = :cid 
                                AND `queue_date` = :today 
                                AND `status` IN ('Called', 'Serving') 
                              LIMIT 1 FOR UPDATE");
    $busyStmt->execute([':cid' => $counterId, ':today' => $today]);
    $busyQueue = $busyStmt->fetch();

    if ($busyQueue) {
        throw new Exception("Counter is already engaged with ticket #{$busyQueue['queue_number']} in '{$busyQueue['status']}' state. Complete or resolve it first.");
    }

    // 3. Find Authorized Services for this counter
    $svcStmt = $pdo->prepare("SELECT cs.service_id 
                              FROM `counter_services` cs 
                              JOIN `services` s ON cs.service_id = s.id 
                              WHERE cs.counter_id = :cid AND s.status = 'active'");
    $svcStmt->execute([':cid' => $counterId]);
    $serviceIds = $svcStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($serviceIds)) {
        throw new Exception("No active services are configured for this counter. Contact administrator.");
    }

    // 4. Atomic Row Selection with Locking (Priority First, then Earliest Registration Time)
    $inClause = implode(',', array_map('intval', $serviceIds));
    $selectSql = "SELECT q.id, q.queue_number, q.service_id, q.status 
                  FROM `queues` q
                  JOIN `queue_priorities` p ON q.priority_id = p.id
                  WHERE q.service_id IN ({$inClause}) 
                    AND q.queue_date = :today 
                    AND q.status = 'Waiting' 
                  ORDER BY p.priority_level DESC, q.registration_time ASC, q.id ASC 
                  LIMIT 1 FOR UPDATE";

    $queueStmt = $pdo->prepare($selectSql);
    $queueStmt->execute([':today' => $today]);
    $targetQueue = $queueStmt->fetch();

    if (!$targetQueue) {
        $pdo->commit();
        set_flash('info', 'There are no waiting customers for your assigned services right now.');
        header('Location: ' . BASE_URL . '/staff/dashboard.php');
        exit();
    }

    $queueId = (int)$targetQueue['id'];

    // 5. Update Status from Waiting to Called
    $updateStmt = $pdo->prepare("UPDATE `queues` 
                                SET `status` = 'Called', 
                                    `called_time` = NOW(), 
                                    `assigned_counter_id` = :cid, 
                                    `assigned_staff_id` = :sid 
                                WHERE `id` = :qid AND `status` = 'Waiting'");
    $updateStmt->execute([
        ':cid' => $counterId,
        ':sid' => $staffId,
        ':qid' => $queueId
    ]);

    // 6. Record Queue Call Log
    $callStmt = $pdo->prepare("INSERT INTO `queue_calls` (`queue_id`, `counter_id`, `staff_id`, `call_type`, `called_at`) 
                               VALUES (:qid, :cid, :sid, 'Initial', NOW())");
    $callStmt->execute([
        ':qid' => $queueId,
        ':cid' => $counterId,
        ':sid' => $staffId
    ]);

    // 7. Record Status Transition Audit
    record_queue_status_transition($pdo, $queueId, 'Waiting', 'Called', $staffId, "Called to {$counter['counter_name']}");

    $pdo->commit();

    log_activity($staffId, 'QUEUE_CALLED', "Called ticket {$targetQueue['queue_number']} to counter {$counter['counter_name']}");
    set_flash('success', "Ticket {$targetQueue['queue_number']} called successfully to {$counter['counter_name']}.");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', $e->getMessage());
}

header('Location: ' . BASE_URL . '/staff/dashboard.php');
exit();
