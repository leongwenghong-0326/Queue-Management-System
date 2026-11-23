<?php
// ============================================================
// Customer - Atomic Queue Registration Handler
// ============================================================

require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/customer/index.php');
    exit();
}

require_csrf();

$serviceId = (int)($_POST['service_id'] ?? 0);
$customerName = trim($_POST['customer_name'] ?? '');
if (empty($customerName)) {
    $customerName = 'Walk-in Customer';
}
$phone = trim($_POST['phone'] ?? '');
$priorityId = (int)($_POST['priority_id'] ?? 1);

// 1. Check Service & Operating Hours Availability
$availability = check_queue_availability($serviceId);
if (!$availability['available']) {
    set_flash('danger', $availability['message']);
    header('Location: ' . BASE_URL . '/customer/index.php');
    exit();
}

$service = $availability['service'];
$serviceCode = $service['service_code'];
$today = date('Y-m-d');

$pdo = Database::getConnection();

try {
    // 2. Start Atomic Database Transaction
    $pdo->beginTransaction();

    // 3. Atomically increment sequence for this service and date using row-level locking
    $seqStmt = $pdo->prepare("INSERT INTO `queue_sequences` (`service_id`, `queue_date`, `last_number`, `updated_at`) 
                             VALUES (:sid, :today, 1, NOW()) 
                             ON DUPLICATE KEY UPDATE `last_number` = `last_number` + 1, `updated_at` = NOW()");
    $seqStmt->execute([':sid' => $serviceId, ':today' => $today]);

    // Fetch the locked last_number
    $fetchSeqStmt = $pdo->prepare("SELECT `last_number` FROM `queue_sequences` WHERE `service_id` = :sid AND `queue_date` = :today FOR UPDATE");
    $fetchSeqStmt->execute([':sid' => $serviceId, ':today' => $today]);
    $nextSeqNumber = (int)$fetchSeqStmt->fetchColumn();

    // Format Queue Number: e.g. A001, B014
    $queueNumber = sprintf("%s%03d", $serviceCode, $nextSeqNumber);

    // 4. Save Customer record if phone or name provided
    $customerId = null;
    $custStmt = $pdo->prepare("INSERT INTO `customers` (`customer_name`, `phone`, `created_at`) VALUES (:cname, :phone, NOW())");
    $custStmt->execute([
        ':cname' => $customerName,
        ':phone' => !empty($phone) ? $phone : null
    ]);
    $customerId = (int)$pdo->lastInsertId();

    // 5. Generate Cryptographically Secure Tracking Token
    $tokenData = generate_tracking_token();
    $rawToken = $tokenData['raw_token'];
    $tokenHash = $tokenData['token_hash'];

    // 6. Insert Queue Record
    $queueStmt = $pdo->prepare("INSERT INTO `queues` 
        (`queue_number`, `service_id`, `customer_id`, `tracking_token_hash`, `priority_id`, `status`, `queue_date`, `registration_time`, `created_at`) 
        VALUES 
        (:qnum, :sid, :cid, :thash, :pid, 'Waiting', :today, NOW(), NOW())");
    
    $queueStmt->execute([
        ':qnum' => $queueNumber,
        ':sid' => $serviceId,
        ':cid' => $customerId,
        ':thash' => $tokenHash,
        ':pid' => $priorityId,
        ':today' => $today
    ]);
    $queueId = (int)$pdo->lastInsertId();

    // 7. Record Status Transition Audit
    record_queue_status_transition($pdo, $queueId, 'Initiated', 'Waiting', null, 'Customer self-registration');

    // 8. Commit Transaction Safely
    $pdo->commit();

    log_activity(null, 'QUEUE_REGISTERED', "Generated ticket {$queueNumber} for service ID {$serviceId}");

    // Redirect to digital ticket
    header("Location: " . BASE_URL . "/customer/ticket.php?token=" . urlencode($rawToken));
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Failed to register queue: " . $e->getMessage());
    set_flash('danger', 'System error while generating ticket. Please try again or approach counter staff.');
    header('Location: ' . BASE_URL . '/customer/index.php');
    exit();
}
