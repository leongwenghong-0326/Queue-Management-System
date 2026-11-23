<?php
// ============================================================
// Core Helper Functions & Business Logic Engine
// ============================================================

/**
 * Escape HTML output to prevent XSS
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Flash message helper for session notifications
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash_messages'][] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

function display_flash(): string {
    if (empty($_SESSION['flash_messages'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash_messages'] as $flash) {
        $type = e($flash['type']);
        $msg = e($flash['message']);
        $html .= "<div class=\"alert alert-{$type} alert-dismissible fade show\" role=\"alert\">
                    <span>{$msg}</span>
                    <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>
                  </div>";
    }
    unset($_SESSION['flash_messages']);
    return $html;
}

/**
 * Generate cryptographically secure tracking token
 */
function generate_tracking_token(): array {
    $rawToken = bin2hex(random_bytes(32)); // 64 hex characters
    $tokenHash = hash('sha256', $rawToken);
    return [
        'raw_token' => $rawToken,
        'token_hash' => $tokenHash
    ];
}

/**
 * Hash raw token for lookup
 */
function hash_tracking_token(string $rawToken): string {
    return hash('sha256', trim($rawToken));
}

/**
 * Record system and user activity log
 */
function log_activity(?int $userId, string $action, ?string $description = null): void {
    try {
        $pdo = Database::getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare("INSERT INTO `activity_logs` (`user_id`, `action`, `description`, `ip_address`, `created_at`) VALUES (:uid, :act, :desc, :ip, NOW())");
        $stmt->execute([
            ':uid' => $userId,
            ':act' => $action,
            ':desc' => $description,
            ':ip' => $ip
        ]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

/**
 * Get system setting with fallback
 */
function get_setting(string $key, ?string $default = null): ?string {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Update system setting
 */
function update_setting(string $key, string $value, ?int $userId = null): bool {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) 
                               VALUES (:k, :v, :uid, NOW()) 
                               ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_by` = VALUES(`updated_by`), `updated_at` = NOW()");
        return $stmt->execute([':k' => $key, ':v' => $value, ':uid' => $userId]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Record queue status transition with audit log
 */
function record_queue_status_transition(PDO $pdo, int $queueId, string $previousStatus, string $newStatus, ?int $changedBy = null, ?string $reason = null): void {
    $stmt = $pdo->prepare("INSERT INTO `queue_status_history` (`queue_id`, `previous_status`, `new_status`, `changed_by`, `reason`, `created_at`) 
                           VALUES (:qid, :prev, :new, :by, :reason, NOW())");
    $stmt->execute([
        ':qid' => $queueId,
        ':prev' => $previousStatus,
        ':new' => $newStatus,
        ':by' => $changedBy,
        ':reason' => $reason
    ]);
}

/**
 * Check if the service and business registration is open and within capacity
 */
function check_queue_availability(int $serviceId): array {
    $pdo = Database::getConnection();
    $today = date('Y-m-d');
    $dayOfWeek = (int)date('w'); // 0=Sunday, 6=Saturday
    $currentTime = date('H:i:s');

    // 1. Check Service Status
    $stmt = $pdo->prepare("SELECT * FROM `services` WHERE `id` = :id LIMIT 1");
    $stmt->execute([':id' => $serviceId]);
    $service = $stmt->fetch();

    if (!$service) {
        return ['available' => false, 'message' => 'Selected service does not exist.'];
    }
    if ($service['status'] !== 'active') {
        return ['available' => false, 'message' => 'The selected service is currently inactive.'];
    }

    // 2. Check Holiday
    $stmt = $pdo->prepare("SELECT * FROM `holidays` WHERE `holiday_date` = :today AND `is_closed` = 1 LIMIT 1");
    $stmt->execute([':today' => $today]);
    $holiday = $stmt->fetch();
    if ($holiday) {
        return ['available' => false, 'message' => "Today is a recognized holiday ({$holiday['holiday_name']}). Registration is closed."];
    }

    // 3. Check Operating Hours
    $stmt = $pdo->prepare("SELECT * FROM `operating_hours` WHERE `day_of_week` = :dow LIMIT 1");
    $stmt->execute([':dow' => $dayOfWeek]);
    $hours = $stmt->fetch();

    if ($hours) {
        if (!$hours['is_open']) {
            return ['available' => false, 'message' => 'Business is closed today according to operating schedule.'];
        }
        if ($currentTime < $hours['registration_start_time'] || $currentTime > $hours['registration_end_time']) {
            $regStart = date('g:i A', strtotime($hours['registration_start_time']));
            $regEnd = date('g:i A', strtotime($hours['registration_end_time']));
            return ['available' => false, 'message' => "Registration is only open between {$regStart} and {$regEnd}."];
        }
    }

    // 4. Check Daily Service Capacity
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `queues` WHERE `service_id` = :sid AND `queue_date` = :today AND `status` != 'Cancelled'");
    $stmt->execute([':sid' => $serviceId, ':today' => $today]);
    $todayCount = (int)$stmt->fetchColumn();

    $maxCapacity = (int)$service['maximum_queue_capacity'];
    if ($maxCapacity > 0 && $todayCount >= $maxCapacity) {
        return ['available' => false, 'message' => "Daily registration capacity ({$maxCapacity} tickets) for this service has been reached."];
    }

    return ['available' => true, 'service' => $service, 'remaining_capacity' => ($maxCapacity > 0 ? $maxCapacity - $todayCount : null)];
}

/**
 * Calculate Dynamic Estimated Waiting Time using Multi-Server Workload Modeling
 * 
 * Takes into account:
 * - Number of customers ahead (filtered by priority level & registration timestamp)
 * - Service-specific 7-day rolling average duration (or configured baseline fallback)
 * - Total authorized active service counters operating currently
 * - Real-time active counter workloads (remaining duration of in-progress serving sessions)
 */
function calculate_estimated_waiting_time(int $serviceId, ?int $targetQueueId = null): int {
    $estimate = get_detailed_queue_estimate_data($serviceId, $targetQueueId);
    return $estimate['estimated_wait_minutes'];
}

/**
 * Detailed Multi-Server Queue Workload Estimator Data Structure
 */
function get_detailed_queue_estimate_data(int $serviceId, ?int $targetQueueId = null): array {
    $pdo = Database::getConnection();
    $today = date('Y-m-d');

    // 1. Service Details & Baseline Duration
    $stmt = $pdo->prepare("SELECT service_code, service_name, default_service_duration FROM `services` WHERE `id` = :id LIMIT 1");
    $stmt->execute([':id' => $serviceId]);
    $service = $stmt->fetch();
    $defaultDuration = (int)($service['default_service_duration'] ?? 10) ?: 10;

    // 2. Dynamic 7-day Historical Completed Average Duration
    $stmt = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, service_start_time, completed_time)) as avg_duration, COUNT(*) as sample_count 
                           FROM `queues` 
                           WHERE `service_id` = :sid 
                             AND `status` = 'Completed' 
                             AND `service_start_time` IS NOT NULL 
                             AND `completed_time` IS NOT NULL 
                             AND `queue_date` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $stmt->execute([':sid' => $serviceId]);
    $historyStats = $stmt->fetch();
    $sampleCount = (int)($historyStats['sample_count'] ?? 0);
    $avgDuration = ($sampleCount >= 2 && (float)$historyStats['avg_duration'] >= 1) 
        ? round((float)$historyStats['avg_duration']) 
        : $defaultDuration;

    // 3. Active Operating Counters Supporting this Service
    $cntStmt = $pdo->prepare("SELECT DISTINCT c.id as counter_id, c.counter_name 
                             FROM `counters` c
                             JOIN `counter_services` cs ON c.id = cs.counter_id
                             JOIN `staff_counter_assignments` sca ON c.id = sca.counter_id AND sca.status = 'active'
                             WHERE cs.service_id = :sid AND c.status = 'active'");
    $cntStmt->execute([':sid' => $serviceId]);
    $activeCountersList = $cntStmt->fetchAll();
    $activeCountersCount = count($activeCountersList);
    $cEffective = max(1, $activeCountersCount); // Multi-server capacity factor

    // 4. Compute Current Counter Workloads (Remaining duration of currently served customers)
    $totalServingRemaining = 0;
    $minServingRemaining = $avgDuration;
    $idleCountersCount = 0;

    if (!empty($activeCountersList)) {
        $counterIds = array_column($activeCountersList, 'counter_id');
        $inCounters = implode(',', array_map('intval', $counterIds));

        $workloadStmt = $pdo->query("SELECT q.assigned_counter_id, q.service_start_time,
                                           TIMESTAMPDIFF(MINUTE, q.service_start_time, NOW()) as elapsed_minutes 
                                    FROM `queues` q 
                                    WHERE q.assigned_counter_id IN ({$inCounters}) 
                                      AND q.queue_date = '{$today}' 
                                      AND q.status = 'Serving'");
        $activeServingSessions = $workloadStmt->fetchAll();
        $servingByCounter = [];
        foreach ($activeServingSessions as $sess) {
            $servingByCounter[$sess['assigned_counter_id']] = (int)($sess['elapsed_minutes'] ?? 0);
        }

        foreach ($counterIds as $cid) {
            if (isset($servingByCounter[$cid])) {
                $elapsed = $servingByCounter[$cid];
                // Remaining time: at least 1 min if session is still underway
                $remaining = max(1, (int)round($avgDuration - $elapsed));
                $totalServingRemaining += $remaining;
                if ($remaining < $minServingRemaining) {
                    $minServingRemaining = $remaining;
                }
            } else {
                $idleCountersCount++;
                $minServingRemaining = 0;
            }
        }
    } else {
        // No staff logged in currently: fallback estimate
        $totalServingRemaining = 0;
        $idleCountersCount = 0;
    }

    // 5. Calculate Position & Estimated Waiting Time
    $customersAhead = 0;
    $estimatedMinutes = 0;
    $queueStatus = 'Waiting';
    $isNext = false;
    $estimateLabel = '';

    if ($targetQueueId !== null) {
        $qStmt = $pdo->prepare("SELECT q.id, q.queue_number, q.registration_time, q.status, p.priority_level 
                                FROM `queues` q 
                                LEFT JOIN `queue_priorities` p ON q.priority_id = p.id 
                                WHERE q.id = :qid LIMIT 1");
        $qStmt->execute([':qid' => $targetQueueId]);
        $target = $qStmt->fetch();

        if (!$target) {
            return [
                'estimated_wait_minutes' => 0,
                'customers_ahead' => 0,
                'active_counters_count' => $activeCountersCount,
                'avg_service_duration' => $avgDuration,
                'is_next_in_line' => false,
                'estimate_label' => '0 mins'
            ];
        }

        $queueStatus = $target['status'];

        if (in_array($queueStatus, ['Called', 'Serving'])) {
            // Already called or serving: 0 wait minutes
            return [
                'estimated_wait_minutes' => 0,
                'customers_ahead' => 0,
                'active_counters_count' => $activeCountersCount,
                'avg_service_duration' => $avgDuration,
                'is_next_in_line' => false,
                'estimate_label' => $queueStatus === 'Called' ? 'Called (Proceed to counter)' : 'Being served now'
            ];
        }

        if (in_array($queueStatus, ['Completed', 'No-Show', 'Cancelled', 'Transferred'])) {
            return [
                'estimated_wait_minutes' => 0,
                'customers_ahead' => 0,
                'active_counters_count' => $activeCountersCount,
                'avg_service_duration' => $avgDuration,
                'is_next_in_line' => false,
                'estimate_label' => $queueStatus
            ];
        }

        // Target is in 'Waiting' status:
        // Calculate ALL customers ahead who have NOT completed service yet:
        // 1. Customers currently at the counter not done serving yet (Called or Serving)
        $inProgressStmt = $pdo->prepare("SELECT COUNT(*) FROM `queues` 
                                         WHERE `service_id` = :sid 
                                           AND `queue_date` = :today 
                                           AND `status` IN ('Called', 'Serving') 
                                           AND `id` != :qid 
                                           AND `registration_time` <= :rtime");
        $inProgressStmt->execute([
            ':sid' => $serviceId,
            ':today' => $today,
            ':qid' => $target['id'],
            ':rtime' => $target['registration_time']
        ]);
        $inProgressCount = (int)$inProgressStmt->fetchColumn();

        // 2. Waiting customers ahead in line (strictly higher priority, or same priority registered earlier)
        $waitingAheadStmt = $pdo->prepare("SELECT COUNT(*) 
                                           FROM `queues` q
                                           LEFT JOIN `queue_priorities` p ON q.priority_id = p.id
                                           WHERE q.service_id = :sid 
                                             AND q.queue_date = :today 
                                             AND q.status = 'Waiting' 
                                             AND q.id != :qid
                                             AND (
                                                 p.priority_level > :plevel1 
                                                 OR (p.priority_level = :plevel2 AND q.registration_time < :rtime)
                                             )");
        $pLevel = $target['priority_level'] ?? 1;
        $waitingAheadStmt->execute([
            ':sid' => $serviceId,
            ':today' => $today,
            ':qid' => $target['id'],
            ':plevel1' => $pLevel,
            ':plevel2' => $pLevel,
            ':rtime' => $target['registration_time']
        ]);
        $waitingAheadCount = (int)$waitingAheadStmt->fetchColumn();

        // Total customers ahead not done serving yet
        $customersAhead = $inProgressCount + $waitingAheadCount;

        if ($customersAhead === 0) {
            $isNext = true;
            if ($idleCountersCount > 0) {
                $estimatedMinutes = 1;
                $estimateLabel = '< 1 min (Next to be called)';
            } else {
                $estimatedMinutes = max(1, $minServingRemaining);
                $estimateLabel = "~{$estimatedMinutes} mins (Next in line)";
            }
        } else {
            // Formula: Distributed across c available parallel servers
            $workloadMinutes = ($customersAhead * $avgDuration) + $totalServingRemaining;
            $estimatedMinutes = max(1, (int)ceil($workloadMinutes / $cEffective));
            $estimateLabel = "~{$estimatedMinutes} mins";
        }

    } else {
        // Kiosk Service Selection Mode (All incomplete customers currently ahead of any new arrival)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `queues` WHERE `service_id` = :sid AND `queue_date` = :today AND `status` IN ('Waiting', 'Called', 'Serving')");
        $stmt->execute([':sid' => $serviceId, ':today' => $today]);
        $customersAhead = (int)$stmt->fetchColumn();

        if ($customersAhead === 0) {
            $estimatedMinutes = $avgDuration;
            $estimateLabel = "~{$avgDuration} mins";
        } else {
            $workloadMinutes = ($customersAhead * $avgDuration) + $totalServingRemaining;
            $estimatedMinutes = max(1, (int)ceil($workloadMinutes / $cEffective));
            $estimateLabel = "~{$estimatedMinutes} mins";
        }
    }

    return [
        'service_id' => $serviceId,
        'estimated_wait_minutes' => $estimatedMinutes,
        'customers_ahead' => $customersAhead,
        'active_counters_count' => $activeCountersCount,
        'avg_service_duration' => $avgDuration,
        'active_workload_remaining' => $totalServingRemaining,
        'is_next_in_line' => $isNext,
        'estimate_label' => $estimateLabel
    ];
}

/**
 * Public Helper to get detailed estimate for any specific queue ID
 */
function get_queue_estimate(int $queueId): array {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT service_id FROM `queues` WHERE `id` = :id LIMIT 1");
    $stmt->execute([':id' => $queueId]);
    $sid = (int)$stmt->fetchColumn();
    if ($sid <= 0) {
        return ['estimated_wait_minutes' => 0, 'customers_ahead' => 0, 'estimate_label' => 'N/A'];
    }
    return get_detailed_queue_estimate_data($sid, $queueId);
}

/**
 * Output JSON Response
 */
function json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit();
}
