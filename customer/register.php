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

$result = generate_queue_ticket($serviceId, $priorityId, $customerName, $phone);

if ($result['success']) {
    log_activity(null, 'QUEUE_REGISTERED', "Generated ticket {$result['queue_number']} for service ID {$serviceId}");
    header("Location: " . BASE_URL . "/customer/ticket.php?token=" . urlencode($result['tracking_token']));
    exit();
} else {
    set_flash('danger', $result['message'] ?? 'Failed to generate queue ticket.');
    header('Location: ' . BASE_URL . '/customer/index.php');
    exit();
}
