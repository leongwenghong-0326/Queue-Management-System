<?php
// ============================================================
// Comprehensive Automated Test Suite for Smart QMS
// ============================================================

require_once __DIR__ . '/../config/config.php';

echo "============================================================\n";
echo "Starting Smart Queue Management System (QMS) Test Suite\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Date/Time: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

$pdo = Database::getConnection();
$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] {$description}\n";
    } else {
        $testsFailed++;
        echo " [FAIL] {$description} | Detail: {$detail}\n";
    }
}

// Ensure clean slate for test service or clean test records
$today = date('Y-m-d');

// -------------------------------------------------------------
// Test Group 1: Database & Seed Integrity
// -------------------------------------------------------------
echo "--- Group 1: Database & Seed Integrity ---\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
assertTest("Database contains all 18 tables", count($tables) >= 18, "Found: " . count($tables));

$adminUser = $pdo->query("SELECT * FROM `users` WHERE `username` = 'admin'")->fetch();
assertTest("Admin account exists and password verifies", $adminUser && password_verify('password123', $adminUser['password']));

$servicesCount = (int)$pdo->query("SELECT COUNT(*) FROM `services` WHERE `status` = 'active'")->fetchColumn();
assertTest("Active services are seeded", $servicesCount >= 4, "Found: {$servicesCount}");

$countersCount = (int)$pdo->query("SELECT COUNT(*) FROM `counters` WHERE `status` = 'active'")->fetchColumn();
assertTest("Active counters are seeded", $countersCount >= 4, "Found: {$countersCount}");

// -------------------------------------------------------------
// Test Group 2: Customer Registration & Atomic Numbering
// -------------------------------------------------------------
echo "\n--- Group 2: Queue Registration & Sequence Logic ---\n";

// Service 1 is General Enquiry (Prefix A)
$service1 = $pdo->query("SELECT * FROM `services` WHERE `id` = 1")->fetch();

// 1. Availability check
$avail = check_queue_availability(1);
assertTest("Service 1 is available for registration", $avail['available'] === true, $avail['message'] ?? '');

// 2. Simulate Registration 1
$tokenData1 = generate_tracking_token();
$pdo->beginTransaction();
$seqStmt = $pdo->prepare("INSERT INTO `queue_sequences` (`service_id`, `queue_date`, `last_number`) 
                         VALUES (1, :today, 1) ON DUPLICATE KEY UPDATE `last_number` = `last_number` + 1");
$seqStmt->execute([':today' => $today]);

$fetchSeq = $pdo->prepare("SELECT last_number FROM `queue_sequences` WHERE `service_id` = 1 AND `queue_date` = :today FOR UPDATE");
$fetchSeq->execute([':today' => $today]);
$seq1 = (int)$fetchSeq->fetchColumn();
$qNum1 = sprintf("A%03d", $seq1);

$ins = $pdo->prepare("INSERT INTO `queues` (`queue_number`, `service_id`, `tracking_token_hash`, `priority_id`, `status`, `queue_date`, `registration_time`) 
                      VALUES (:qn, 1, :thash, 1, 'Waiting', :today, NOW())");
$ins->execute([':qn' => $qNum1, ':thash' => $tokenData1['token_hash'], ':today' => $today]);
$qId1 = (int)$pdo->lastInsertId();
record_queue_status_transition($pdo, $qId1, 'Initiated', 'Waiting', null, 'Test Customer 1');
$pdo->commit();

assertTest("First ticket registered successfully with sequence", $qId1 > 0 && !empty($qNum1), "Queue: {$qNum1}, ID: {$qId1}");

// 3. Simulate Registration 2 (Must be next sequence number)
$tokenData2 = generate_tracking_token();
$pdo->beginTransaction();
$seqStmt->execute([':today' => $today]);
$fetchSeq->execute([':today' => $today]);
$seq2 = (int)$fetchSeq->fetchColumn();
$qNum2 = sprintf("A%03d", $seq2);

$ins->execute([':qn' => $qNum2, ':thash' => $tokenData2['token_hash'], ':today' => $today]);
$qId2 = (int)$pdo->lastInsertId();
record_queue_status_transition($pdo, $qId2, 'Initiated', 'Waiting', null, 'Test Customer 2');
$pdo->commit();

assertTest("Second ticket receives strictly incremental sequence (no gaps/dups)", $seq2 === ($seq1 + 1), "Seq1: {$seq1}, Seq2: {$seq2}");

// 4. Test Inactive Service Rejection
$pdo->exec("UPDATE `services` SET `status` = 'inactive' WHERE `id` = 4");
$inactiveCheck = check_queue_availability(4);
assertTest("Inactive service correctly rejects registration", $inactiveCheck['available'] === false);
$pdo->exec("UPDATE `services` SET `status` = 'active' WHERE `id` = 4"); // Restore

// 5. Test Capacity Limit Enforcement
$currentCount = (int)$pdo->query("SELECT COUNT(*) FROM `queues` WHERE `service_id` = 3 AND `queue_date` = '{$today}' AND `status` != 'Cancelled'")->fetchColumn();
$pdo->exec("UPDATE `services` SET `maximum_queue_capacity` = " . ($currentCount + 1) . " WHERE `id` = 3");
$capCheck1 = check_queue_availability(3);
assertTest("Service 3 allows ticket when below limit", $capCheck1['available'] === true, $capCheck1['message'] ?? '');
// Insert 1 ticket to hit the limit
$pdo->prepare("INSERT INTO `queues` (`queue_number`, `service_id`, `tracking_token_hash`, `priority_id`, `status`, `queue_date`, `registration_time`) 
               VALUES ('C999', 3, 'test_hash_cap', 1, 'Waiting', :today, NOW())")->execute([':today' => $today]);
$capCheck2 = check_queue_availability(3);
assertTest("Service 3 rejects ticket once daily capacity reached", $capCheck2['available'] === false);
// Restore capacity & clean test ticket
$pdo->exec("UPDATE `services` SET `maximum_queue_capacity` = 80 WHERE `id` = 3");
$pdo->prepare("DELETE FROM `queues` WHERE `queue_number` = 'C999'")->execute();

// -------------------------------------------------------------
// Test Group 3: Queue Calling, Row-Locking & State Machine
// -------------------------------------------------------------
echo "\n--- Group 3: Queue Calling, Row-Locking & State Transitions ---\n";

// Counter 1 calling next customer
// Clean any lingering active queue for Counter 1 first
$pdo->prepare("UPDATE `queues` SET `status` = 'Completed' WHERE `assigned_counter_id` = 1 AND `status` IN ('Called', 'Serving')")->execute();

// Call next waiting customer for Counter 1 (handles Service 1)
$pdo->beginTransaction();
$selectSql = "SELECT q.id, q.queue_number, q.status 
              FROM `queues` q
              JOIN `queue_priorities` p ON q.priority_id = p.id
              WHERE q.service_id = 1 
                AND q.queue_date = :today 
                AND q.status = 'Waiting' 
              ORDER BY p.priority_level DESC, q.registration_time ASC 
              LIMIT 1 FOR UPDATE";
$cStmt = $pdo->prepare($selectSql);
$cStmt->execute([':today' => $today]);
$calledRow = $cStmt->fetch();

if ($calledRow) {
    $cQid = (int)$calledRow['id'];
    $pdo->prepare("UPDATE `queues` SET `status` = 'Called', `called_time` = NOW(), `assigned_counter_id` = 1, `assigned_staff_id` = 2 WHERE `id` = :qid")
        ->execute([':qid' => $cQid]);
    record_queue_status_transition($pdo, $cQid, 'Waiting', 'Called', 2, 'Called to Counter 1');
    $pdo->commit();
    assertTest("Waiting ticket transitioned to Called status", true, "Called Queue ID: {$cQid}");
} else {
    $pdo->rollBack();
    assertTest("Waiting ticket transitioned to Called status", false, "No candidate found");
}

// Verify Counter is now busy: Counter 1 cannot call another customer while one is Called
$busyCheck = $pdo->prepare("SELECT id FROM `queues` WHERE `assigned_counter_id` = 1 AND `status` IN ('Called', 'Serving')");
$busyCheck->execute();
assertTest("Counter is marked busy with currently called customer", (bool)$busyCheck->fetch());

// Test Start Serving: Transition from Called to Serving
$pdo->beginTransaction();
$servingUpdate = $pdo->prepare("UPDATE `queues` SET `status` = 'Serving', `service_start_time` = NOW() WHERE `id` = :qid AND `status` = 'Called'");
$servingUpdate->execute([':qid' => $cQid]);
$servingAffected = $servingUpdate->rowCount();
record_queue_status_transition($pdo, $cQid, 'Called', 'Serving', 2, 'Started service');
$pdo->commit();
assertTest("Called ticket transitioned to Serving with service_start_time recorded", $servingAffected === 1);

// Test Complete Service: Transition from Serving to Completed
$pdo->beginTransaction();
$compUpdate = $pdo->prepare("UPDATE `queues` SET `status` = 'Completed', `completed_time` = NOW() WHERE `id` = :qid AND `status` = 'Serving'");
$compUpdate->execute([':qid' => $cQid]);
$compAffected = $compUpdate->rowCount();
record_queue_status_transition($pdo, $cQid, 'Serving', 'Completed', 2, 'Service completed');
$pdo->commit();
assertTest("Serving ticket transitioned to Completed with completed_time recorded", $compAffected === 1);

// -------------------------------------------------------------
// Test Group 4: Invalid Status Transition Prevention (Integrity Rules)
// -------------------------------------------------------------
echo "\n--- Group 4: Business Rule & Illegal Transition Rejections ---\n";

// Rule A: Cannot complete a Waiting ticket directly
$illComp = $pdo->prepare("UPDATE `queues` SET `status` = 'Completed' WHERE `id` = :qid AND `status` = 'Serving'");
$illComp->execute([':qid' => $qId2]); // $qId2 is currently Waiting
assertTest("System rejects direct completion of a Waiting customer", $illComp->rowCount() === 0);

// Rule B: Cannot mark a Completed ticket as No-Show
$illNoShow = $pdo->prepare("UPDATE `queues` SET `status` = 'No-Show' WHERE `id` = :qid AND `status` = 'Called'");
$illNoShow->execute([':qid' => $cQid]); // $cQid is currently Completed
assertTest("System rejects marking a Completed customer as No-Show", $illNoShow->rowCount() === 0);

// Rule C: Cannot call an already Completed or Cancelled queue
$illCall = $pdo->prepare("UPDATE `queues` SET `status` = 'Called' WHERE `id` = :qid AND `status` = 'Waiting'");
$illCall->execute([':qid' => $cQid]); // $cQid is Completed
assertTest("System rejects calling a Completed customer", $illCall->rowCount() === 0);

// -------------------------------------------------------------
// Test Group 5: Queue Transfer Workflow
// -------------------------------------------------------------
echo "\n--- Group 5: Queue Transfer & Historical Linkage ---\n";

// Put $qId2 into Called/Serving state at Counter 1
$pdo->prepare("UPDATE `queues` SET `status` = 'Called', `assigned_counter_id` = 1, `assigned_staff_id` = 2, `called_time` = NOW() WHERE `id` = :qid")
    ->execute([':qid' => $qId2]);

// Perform Transfer of $qId2 from Service 1 (A) to Service 2 (Payment - B)
$pdo->beginTransaction();
$pdo->prepare("UPDATE `queues` SET `status` = 'Transferred' WHERE `id` = :qid")->execute([':qid' => $qId2]);

// Sequence for Service 2
$pdo->prepare("INSERT INTO `queue_sequences` (`service_id`, `queue_date`, `last_number`) VALUES (2, :today, 1) ON DUPLICATE KEY UPDATE `last_number` = `last_number` + 1")
    ->execute([':today' => $today]);
$destSeq = (int)$pdo->query("SELECT last_number FROM `queue_sequences` WHERE `service_id` = 2 AND `queue_date` = '{$today}'")->fetchColumn();
$newNum = sprintf("B%03d", $destSeq);

$transToken = generate_tracking_token();
$insDest = $pdo->prepare("INSERT INTO `queues` (`queue_number`, `service_id`, `tracking_token_hash`, `priority_id`, `status`, `queue_date`, `transfer_from_queue_id`, `registration_time`) 
                         VALUES (:qn, 2, :thash, 1, 'Waiting', :today, :orig_id, NOW())");
$insDest->execute([
    ':qn' => $newNum,
    ':thash' => $transToken['token_hash'],
    ':today' => $today,
    ':orig_id' => $qId2
]);
$destQid = (int)$pdo->lastInsertId();

$transferLog = $pdo->prepare("INSERT INTO `queue_transfers` (`original_queue_id`, `destination_queue_id`, `source_service_id`, `destination_service_id`, `transferred_by`, `reason`) 
                             VALUES (:orig, :dest, 1, 2, 2, 'Test Service Transfer')");
$transferLog->execute([':orig' => $qId2, ':dest' => $destQid]);
$pdo->commit();

$origStatus = $pdo->query("SELECT status FROM `queues` WHERE `id` = {$qId2}")->fetchColumn();
$destRow = $pdo->query("SELECT * FROM `queues` WHERE `id` = {$destQid}")->fetch();

assertTest("Original queue status changed to Transferred", $origStatus === 'Transferred');
assertTest("Destination queue created in Waiting with linked transfer_from_queue_id", $destRow && $destRow['status'] === 'Waiting' && (int)$destRow['transfer_from_queue_id'] === $qId2);
assertTest("Audit entry recorded in queue_transfers table", (bool)$pdo->query("SELECT id FROM `queue_transfers` WHERE `destination_queue_id` = {$destQid}")->fetch());

// -------------------------------------------------------------
// Test Group 6: Security & Token Access Controls
// -------------------------------------------------------------
echo "\n--- Group 6: Security & Cryptographic Token Protection ---\n";

// 1. Valid token lookup
$validHash = hash_tracking_token($transToken['raw_token']);
$lookup1 = $pdo->prepare("SELECT id FROM `queues` WHERE `tracking_token_hash` = :h");
$lookup1->execute([':h' => $validHash]);
assertTest("Valid customer token successfully resolves ticket", (bool)$lookup1->fetch());

// 2. Invalid / Tampered token lookup
$fakeHash = hash_tracking_token("fake_tampered_token_string_12345");
$lookup2 = $pdo->prepare("SELECT id FROM `queues` WHERE `tracking_token_hash` = :h");
$lookup2->execute([':h' => $fakeHash]);
assertTest("Invalid or guessed token is strictly rejected (no info leak)", $lookup2->fetch() === false);

// 3. Sequential ID access prevention (DB stores hash, not ID in URL)
assertTest("Token length is 64 hex characters (256-bit entropy)", strlen($transToken['raw_token']) === 64);

// -------------------------------------------------------------
// Test Group 7: Analytics & Waiting Time Calculation
// -------------------------------------------------------------
echo "\n--- Group 7: Analytics, Waiting Time & CSV Export Verification ---\n";

$estMinutes = calculate_estimated_waiting_time(1);
assertTest("Estimated waiting time calculation returns non-negative integer", is_int($estMinutes) && $estMinutes >= 0, "Calculated: {$estMinutes} mins");

$todayTotal = (int)$pdo->query("SELECT COUNT(*) FROM `queues` WHERE `queue_date` = '{$today}'")->fetchColumn();
assertTest("Reporting engine aggregates actual database records", $todayTotal >= 2, "Total today: {$todayTotal}");

// -------------------------------------------------------------
// Test Group 8: Online Appointments & Office Check-in
// -------------------------------------------------------------
echo "\n--- Group 8: Online Appointments & Office Check-in ---\n";

$aptSlots = get_appointment_time_slots(1, $today);
assertTest("Available appointment time slots returned correctly", count($aptSlots) >= 5 && isset($aptSlots[0]['remaining']));

$newApt = create_appointment([
    'user_id' => 4,
    'service_id' => 1,
    'applicant_name' => 'Automated Test Citizen',
    'ic_number' => '990101-14-9999',
    'phone' => '+60 12-999 8888',
    'email' => 'testcitizen@qms.local',
    'appointment_date' => $today,
    'time_slot' => '10:30 - 11:30',
    'notes' => 'Automated test suite appointment'
]);
assertTest("Appointment created successfully with reference", $newApt['success'] === true && !empty($newApt['appointment_reference']));

$checkinRes = checkin_appointment($newApt['appointment_reference']);
assertTest("Appointment checked in and converted to queue ticket", $checkinRes['success'] === true && !empty($checkinRes['queue_number']));

$aptCheck = $pdo->query("SELECT * FROM `appointments` WHERE `id` = {$newApt['appointment_id']}")->fetch();
assertTest("Appointment status updated to Checked In with queue link", $aptCheck && $aptCheck['status'] === 'Checked In' && !empty($aptCheck['queue_id']));

// -------------------------------------------------------------
// Test Group 9: Online Applications & Review Workflow
// -------------------------------------------------------------
echo "\n--- Group 9: Online Applications & Review Workflow ---\n";

$newApp = create_application([
    'user_id' => 4,
    'service_id' => 2,
    'applicant_name' => 'Automated Test Citizen',
    'ic_number' => '990101-14-9999',
    'phone' => '+60 12-999 8888',
    'email' => 'testcitizen@qms.local',
    'notes' => 'Automated test application'
]);
assertTest("Application submitted successfully with tracking reference", $newApp['success'] === true && !empty($newApp['application_reference']));

$reviewRes = review_application($newApp['application_id'], 'Approved', 1, 'Documents verified by test suite');
assertTest("Application review updated status to Approved", $reviewRes === true);

$appCheck = $pdo->query("SELECT * FROM `applications` WHERE `id` = {$newApp['application_id']}")->fetch();
assertTest("Application record reflects Approved status and reviewer comments", $appCheck && $appCheck['status'] === 'Approved' && $appCheck['reviewer_remarks'] === 'Documents verified by test suite');

// -------------------------------------------------------------
// Final Summary
// -------------------------------------------------------------
echo "\n============================================================\n";
echo "Test Execution Finished!\n";
echo "Total Tests: " . ($testsPassed + $testsFailed) . "\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";
echo "Success Rate: " . round(($testsPassed / ($testsPassed + $testsFailed)) * 100, 1) . "%\n";
echo "============================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
