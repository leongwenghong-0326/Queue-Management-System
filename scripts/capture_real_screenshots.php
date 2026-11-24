<?php
// ============================================================
// Capture Real Screenshots Script (Headless Chrome)
// ============================================================

require_once __DIR__ . '/../config/config.php';

$outputDir = __DIR__ . '/../docs/screenshots';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$pdo = Database::getConnection();

// 1. Ensure a fresh realistic ticket exists for screenshots
$serviceStmt = $pdo->query("SELECT id, service_code FROM services WHERE status = 'active' LIMIT 1");
$service = $serviceStmt->fetch();
$serviceId = (int)$service['id'];
$serviceCode = $service['service_code'];
$today = date('Y-m-d');

// Register customer
$custStmt = $pdo->prepare("INSERT INTO customers (customer_name, phone) VALUES ('David Miller', '+60 12-345 6789')");
$custStmt->execute();
$customerId = (int)$pdo->lastInsertId();

// Sequence
$seqStmt = $pdo->prepare("INSERT INTO queue_sequences (service_id, queue_date, last_number, updated_at) 
                         VALUES (:sid, :today, 1, NOW()) 
                         ON DUPLICATE KEY UPDATE last_number = last_number + 1, updated_at = NOW()");
$seqStmt->execute([':sid' => $serviceId, ':today' => $today]);

$fetchSeq = $pdo->prepare("SELECT last_number FROM queue_sequences WHERE service_id = :sid AND queue_date = :today");
$fetchSeq->execute([':sid' => $serviceId, ':today' => $today]);
$num = (int)$fetchSeq->fetchColumn();
$queueNum = sprintf("%s%03d", $serviceCode, $num);

$tokens = generate_tracking_token();
$rawToken = $tokens['raw_token'];
$tokenHash = $tokens['token_hash'];

$qStmt = $pdo->prepare("INSERT INTO queues (queue_number, service_id, customer_id, tracking_token_hash, priority_id, status, queue_date, registration_time) 
                       VALUES (:qnum, :sid, :cid, :thash, 1, 'Waiting', :today, NOW())");
$qStmt->execute([
    ':qnum' => $queueNum,
    ':sid' => $serviceId,
    ':cid' => $customerId,
    ':thash' => $tokenHash,
    ':today' => $today
]);
$queueId = (int)$pdo->lastInsertId();
record_queue_status_transition($pdo, $queueId, 'Initiated', 'Waiting', null, 'Demo registration');

echo "Created Demo Ticket: {$queueNum} with raw token: {$rawToken}\n";

// 2. Create temporary auth bridges
$adminBridgeFile = __DIR__ . '/../auth/temp_admin_bridge.php';
$staffBridgeFile = __DIR__ . '/../auth/temp_staff_bridge.php';
$citizenBridgeFile = __DIR__ . '/../auth/temp_citizen_bridge.php';

file_put_contents($adminBridgeFile, '<?php
require_once __DIR__ . "/../config/config.php";
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = \'admin\' LIMIT 1");
$user = $stmt->fetch();
if ($user) {
    $_SESSION[\'user_id\'] = $user[\'id\'];
    $_SESSION[\'username\'] = $user[\'username\'];
    $_SESSION[\'user_name\'] = $user[\'name\'];
    $_SESSION[\'role_id\'] = $user[\'role_id\'];
    $_SESSION[\'role_name\'] = $user[\'role_name\'];
}
$target = $_GET[\'to\'] ?? \'admin/dashboard.php\';
header("Location: " . BASE_URL . "/" . ltrim($target, "/"));
exit();
');

file_put_contents($staffBridgeFile, '<?php
require_once __DIR__ . "/../config/config.php";
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = \'staff1\' LIMIT 1");
$user = $stmt->fetch();
if ($user) {
    $_SESSION[\'user_id\'] = $user[\'id\'];
    $_SESSION[\'username\'] = $user[\'username\'];
    $_SESSION[\'user_name\'] = $user[\'name\'];
    $_SESSION[\'role_id\'] = $user[\'role_id\'];
    $_SESSION[\'role_name\'] = $user[\'role_name\'];
}
$target = $_GET[\'to\'] ?? \'staff/dashboard.php\';
header("Location: " . BASE_URL . "/" . ltrim($target, "/"));
exit();
');

file_put_contents($citizenBridgeFile, '<?php
require_once __DIR__ . "/../config/config.php";
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = \'citizen1\' LIMIT 1");
$user = $stmt->fetch();
if ($user) {
    $_SESSION[\'user_id\'] = $user[\'id\'];
    $_SESSION[\'username\'] = $user[\'username\'];
    $_SESSION[\'user_name\'] = $user[\'name\'];
    $_SESSION[\'role_id\'] = $user[\'role_id\'];
    $_SESSION[\'role_name\'] = $user[\'role_name\'];
}
$target = $_GET[\'to\'] ?? \'user/dashboard.php\';
header("Location: " . BASE_URL . "/" . ltrim($target, "/"));
exit();
');

// 3. Define screenshot targets
$projectBase = 'http://localhost/queue_management_system';
$targets = [
    '01-login.png'           => $projectBase . '/auth/login.php',
    '02-kiosk.png'           => $projectBase . '/customer/index.php',
    '03-ticket.png'          => $projectBase . '/customer/ticket.php?token=' . $rawToken,
    '04-tracker.png'         => $projectBase . '/customer/track.php?token=' . $rawToken,
    '05-display.png'         => $projectBase . '/display/index.php',
    '06-staff-dashboard.png' => $projectBase . '/auth/temp_staff_bridge.php?to=staff/dashboard.php',
    '07-admin-dashboard.png' => $projectBase . '/auth/temp_admin_bridge.php?to=admin/dashboard.php',
    '08-admin-monitor.png'   => $projectBase . '/auth/temp_admin_bridge.php?to=admin/queues/index.php',
    '09-admin-reports.png'   => $projectBase . '/auth/temp_admin_bridge.php?to=admin/reports/index.php',
    '10-appointments.png'    => $projectBase . '/auth/temp_citizen_bridge.php?to=user/appointments.php',
    '11-checkin.png'         => $projectBase . '/customer/checkin.php',
    '12-applications.png'    => $projectBase . '/auth/temp_admin_bridge.php?to=admin/applications/index.php'
];

$chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

foreach ($targets as $fileName => $url) {
    $dest = realpath($outputDir) . DIRECTORY_SEPARATOR . $fileName;
    echo "Capturing {$fileName} from {$url} ... ";
    
    $cmd = sprintf(
        '"%s" --headless=new --disable-gpu --window-size=1280,800 --virtual-time-budget=3000 --screenshot="%s" "%s"',
        $chromePath,
        $dest,
        $url
    );
    
    exec($cmd, $output, $returnCode);
    
    if (file_exists($dest) && filesize($dest) > 1000) {
        echo "SUCCESS (" . round(filesize($dest) / 1024, 1) . " KB)\n";
    } else {
        echo "FAILED (code {$returnCode})\n";
    }
}

// 4. Cleanup temporary bridge files
@unlink($adminBridgeFile);
@unlink($staffBridgeFile);
@unlink($citizenBridgeFile);

echo "\nAll real screenshots captured and saved to docs/screenshots/\n";
