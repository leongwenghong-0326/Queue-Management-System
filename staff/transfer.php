<?php
// ============================================================
// Staff - Transfer Customer to Another Service
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_csrf();

$staffId = (int)$_SESSION['user_id'];
$queueId = (int)($_POST['queue_id'] ?? 0);
$counterId = (int)($_POST['counter_id'] ?? ($_SESSION['active_counter_id'] ?? 0));
$destinationServiceId = (int)($_POST['destination_service_id'] ?? 0);
$reason = trim($_POST['reason'] ?? 'Customer transfer requested');

if ($queueId <= 0 || $counterId <= 0 || $destinationServiceId <= 0) {
    set_flash('danger', 'Please provide a valid destination service and active queue.');
    header('Location: ' . BASE_URL . '/staff/dashboard.php');
    exit();
}

$pdo = Database::getConnection();
$today = date('Y-m-d');

try {
    $pdo->beginTransaction();

    // 1. Verify Original Queue is currently Called or Serving at this counter
    $stmt = $pdo->prepare("SELECT q.*, s.service_code as orig_code 
                           FROM `queues` q 
                           JOIN `services` s ON q.service_id = s.id 
                           WHERE q.id = :qid AND q.assigned_counter_id = :cid FOR UPDATE");
    $stmt->execute([':qid' => $queueId, ':cid' => $counterId]);
    $origQueue = $stmt->fetch();

    if (!$origQueue) {
        throw new Exception("Original queue record not found or not assigned to your counter.");
    }
    if (!in_array($origQueue['status'], ['Called', 'Serving'])) {
        throw new Exception("Only active queues in 'Called' or 'Serving' status can be transferred.");
    }
    if ((int)$origQueue['service_id'] === $destinationServiceId) {
        throw new Exception("Destination service cannot be the same as current service.");
    }

    // 2. Verify Destination Service
    $destSvcStmt = $pdo->prepare("SELECT * FROM `services` WHERE `id` = :id AND `status` = 'active' LIMIT 1");
    $destSvcStmt->execute([':id' => $destinationServiceId]);
    $destService = $destSvcStmt->fetch();
    if (!$destService) {
        throw new Exception("Selected destination service is invalid or inactive.");
    }

    // 3. Mark Original Queue as Transferred
    $prevStatus = $origQueue['status'];
    $updOrig = $pdo->prepare("UPDATE `queues` SET `status` = 'Transferred' WHERE `id` = :qid");
    $updOrig->execute([':qid' => $queueId]);

    record_queue_status_transition($pdo, $queueId, $prevStatus, 'Transferred', $staffId, "Transferred to [{$destService['service_code']}] {$destService['service_name']}. Reason: {$reason}");

    // 4. Generate New Sequence Number for Destination Service
    $seqStmt = $pdo->prepare("INSERT INTO `queue_sequences` (`service_id`, `queue_date`, `last_number`, `updated_at`) 
                             VALUES (:sid, :today, 1, NOW()) 
                             ON DUPLICATE KEY UPDATE `last_number` = `last_number` + 1, `updated_at` = NOW()");
    $seqStmt->execute([':sid' => $destinationServiceId, ':today' => $today]);

    $fetchSeqStmt = $pdo->prepare("SELECT `last_number` FROM `queue_sequences` WHERE `service_id` = :sid AND `queue_date` = :today FOR UPDATE");
    $fetchSeqStmt->execute([':sid' => $destinationServiceId, ':today' => $today]);
    $nextSeqNumber = (int)$fetchSeqStmt->fetchColumn();

    $newQueueNumber = sprintf("%s%03d", $destService['service_code'], $nextSeqNumber);

    // 5. Generate fresh tracking token for transferred ticket
    $tokenData = generate_tracking_token();

    // 6. Insert Destination Queue in Waiting Status linked to original
    $insertDestStmt = $pdo->prepare("INSERT INTO `queues` 
        (`queue_number`, `service_id`, `customer_id`, `tracking_token_hash`, `priority_id`, `status`, `queue_date`, `registration_time`, `transfer_from_queue_id`, `created_at`) 
        VALUES 
        (:qnum, :sid, :cid, :thash, :pid, 'Waiting', :today, NOW(), :orig_id, NOW())");
    $insertDestStmt->execute([
        ':qnum' => $newQueueNumber,
        ':sid' => $destinationServiceId,
        ':cid' => $origQueue['customer_id'],
        ':thash' => $tokenData['token_hash'],
        ':pid' => $origQueue['priority_id'],
        ':today' => $today,
        ':orig_id' => $queueId
    ]);
    $newQueueId = (int)$pdo->lastInsertId();

    record_queue_status_transition($pdo, $newQueueId, 'Initiated', 'Waiting', $staffId, "Transferred in from original ticket {$origQueue['queue_number']}");

    // 7. Insert Audit Record in queue_transfers
    $transferStmt = $pdo->prepare("INSERT INTO `queue_transfers` 
        (`original_queue_id`, `destination_queue_id`, `source_service_id`, `destination_service_id`, `transferred_by`, `reason`, `created_at`) 
        VALUES 
        (:orig_id, :dest_id, :src_sid, :dest_sid, :uid, :reason, NOW())");
    $transferStmt->execute([
        ':orig_id' => $queueId,
        ':dest_id' => $newQueueId,
        ':src_sid' => $origQueue['service_id'],
        ':dest_sid' => $destinationServiceId,
        ':uid' => $staffId,
        ':reason' => $reason
    ]);

    $pdo->commit();
    log_activity($staffId, 'QUEUE_TRANSFERRED', "Transferred {$origQueue['queue_number']} to {$newQueueNumber} ([{$destService['service_code']}] {$destService['service_name']})");
    set_flash('success', "Ticket {$origQueue['queue_number']} successfully transferred to {$newQueueNumber} ([{$destService['service_code']}] {$destService['service_name']}).");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', $e->getMessage());
}

header('Location: ' . BASE_URL . '/staff/dashboard.php');
exit();
