<?php
// ============================================================
// Admin - System Settings & Operating Schedule Configuration
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pageTitle = 'System Settings & Schedule';
$pdo = Database::getConnection();

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $section = $_POST['section'] ?? '';

    if ($section === 'general') {
        $fields = [
            'business_name', 'business_address', 'business_phone',
            'max_recall_attempts', 'recall_wait_seconds', 'no_show_wait_minutes',
            'enable_audio_announcement', 'announcement_voice_lang', 'priority_fairness_ratio'
        ];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_setting($field, trim($_POST[$field]), $_SESSION['user_id']);
            }
        }
        log_activity($_SESSION['user_id'], 'SETTINGS_UPDATED', 'Updated general system settings');
        set_flash('success', 'General system settings saved successfully.');
        header('Location: ' . BASE_URL . '/admin/settings/index.php');
        exit();
    } elseif ($section === 'schedule') {
        $days = $_POST['schedule'] ?? [];
        $stmt = $pdo->prepare("UPDATE `operating_hours` 
                               SET `opening_time` = :ot, `closing_time` = :ct, `registration_start_time` = :rst, `registration_end_time` = :ret, `is_open` = :io 
                               WHERE `day_of_week` = :dow");

        foreach ($days as $dow => $sch) {
            $isOpen = isset($sch['is_open']) ? 1 : 0;
            $stmt->execute([
                ':ot' => $sch['opening_time'] ?? '08:30:00',
                ':ct' => $sch['closing_time'] ?? '17:30:00',
                ':rst' => $sch['registration_start_time'] ?? '08:30:00',
                ':ret' => $sch['registration_end_time'] ?? '17:00:00',
                ':io' => $isOpen,
                ':dow' => (int)$dow
            ]);
        }
        log_activity($_SESSION['user_id'], 'SCHEDULE_UPDATED', 'Updated weekly operating schedule');
        set_flash('success', 'Operating hours and registration schedule updated.');
        header('Location: ' . BASE_URL . '/admin/settings/index.php');
        exit();
    } elseif ($section === 'add_holiday') {
        $hDate = $_POST['holiday_date'] ?? '';
        $hName = trim($_POST['holiday_name'] ?? '');
        if (!empty($hDate) && !empty($hName)) {
            $stmt = $pdo->prepare("INSERT INTO `holidays` (`holiday_date`, `holiday_name`, `is_closed`) VALUES (:d, :n, 1) ON DUPLICATE KEY UPDATE `holiday_name` = VALUES(`holiday_name`)");
            $stmt->execute([':d' => $hDate, ':n' => $hName]);
            log_activity($_SESSION['user_id'], 'HOLIDAY_ADDED', "Added holiday: {$hDate} ({$hName})");
            set_flash('success', 'Holiday added successfully.');
        }
        header('Location: ' . BASE_URL . '/admin/settings/index.php');
        exit();
    } elseif ($section === 'delete_holiday') {
        $hid = (int)($_POST['holiday_id'] ?? 0);
        $pdo->prepare("DELETE FROM `holidays` WHERE `id` = :id")->execute([':id' => $hid]);
        log_activity($_SESSION['user_id'], 'HOLIDAY_DELETED', "Deleted holiday ID: {$hid}");
        set_flash('success', 'Holiday removed.');
        header('Location: ' . BASE_URL . '/admin/settings/index.php');
        exit();
    }
}

// Fetch operating hours
$hours = $pdo->query("SELECT * FROM `operating_hours` ORDER BY day_of_week ASC")->fetchAll();
$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Fetch holidays
$holidays = $pdo->query("SELECT * FROM `holidays` WHERE `holiday_date` >= CURDATE() ORDER BY holiday_date ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">System Configuration & Operating Schedule</h4>
        <p class="text-muted small mb-0">Manage business policies, calling thresholds, operating hours, and official holidays.</p>
    </div>
</div>

<div class="row g-4">
    <!-- General Parameters -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-gear-fill me-2 text-primary"></i>General & Queue Call Rules</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="general">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Business Organization Name</label>
                        <input type="text" name="business_name" class="form-control" value="<?= e(get_setting('business_name', 'Smart City Service Center')) ?>" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Contact Phone</label>
                            <input type="text" name="business_phone" class="form-control" value="<?= e(get_setting('business_phone', '+60 3-8888 9999')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Address / Branch</label>
                            <input type="text" name="business_address" class="form-control" value="<?= e(get_setting('business_address', 'Main Service Hall')) ?>">
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Max Recall Attempts</label>
                            <input type="number" name="max_recall_attempts" class="form-control" value="<?= (int)get_setting('max_recall_attempts', '3') ?>" min="1" max="10" required>
                            <small class="text-muted">Maximum times staff can re-announce a called ticket.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Recall Cooldown (seconds)</label>
                            <input type="number" name="recall_wait_seconds" class="form-control" value="<?= (int)get_setting('recall_wait_seconds', '30') ?>" min="5" max="300" required>
                            <small class="text-muted">Minimum seconds between successive recalls.</small>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">No-Show Tolerance (minutes)</label>
                            <input type="number" name="no_show_wait_minutes" class="form-control" value="<?= (int)get_setting('no_show_wait_minutes', '5') ?>" min="1" max="60" required>
                            <small class="text-muted">Recommended elapsed time before marking No-Show.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Priority Fairness Ratio</label>
                            <input type="number" name="priority_fairness_ratio" class="form-control" value="<?= (int)get_setting('priority_fairness_ratio', '3') ?>" min="1" max="10" required>
                            <small class="text-muted">Call at least 1 Normal after this many Priority queues.</small>
                        </div>
                    </div>

                    <div class="row g-2 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Display Audio Chime</label>
                            <select name="enable_audio_announcement" class="form-select">
                                <option value="1" <?= get_setting('enable_audio_announcement', '1') == '1' ? 'selected' : '' ?>>Enabled (Speech & Bell)</option>
                                <option value="0" <?= get_setting('enable_audio_announcement', '1') == '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Speech Synthesis Voice</label>
                            <select name="announcement_voice_lang" class="form-select">
                                <option value="en-US" <?= get_setting('announcement_voice_lang', 'en-US') == 'en-US' ? 'selected' : '' ?>>English (en-US)</option>
                                <option value="en-GB" <?= get_setting('announcement_voice_lang', 'en-US') == 'en-GB' ? 'selected' : '' ?>>English (en-GB)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save General Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Operating Schedule & Holidays -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Weekly Operating & Registration Hours</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="schedule">

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Day</th>
                                    <th>Status</th>
                                    <th>Service Hours</th>
                                    <th>Registration Window</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hours as $h): ?>
                                    <?php $d = (int)$h['day_of_week']; ?>
                                    <tr>
                                        <td class="fw-bold small"><?= $dayNames[$d] ?></td>
                                        <td>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="schedule[<?= $d ?>][is_open]" value="1" <?= $h['is_open'] ? 'checked' : '' ?>>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <input type="time" name="schedule[<?= $d ?>][opening_time]" class="form-control form-control-sm" value="<?= $h['opening_time'] ?>">
                                                <span>-</span>
                                                <input type="time" name="schedule[<?= $d ?>][closing_time]" class="form-control form-control-sm" value="<?= $h['closing_time'] ?>">
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <input type="time" name="schedule[<?= $d ?>][registration_start_time]" class="form-control form-control-sm" value="<?= $h['registration_start_time'] ?>">
                                                <span>-</span>
                                                <input type="time" name="schedule[<?= $d ?>][registration_end_time]" class="form-control form-control-sm" value="<?= $h['registration_end_time'] ?>">
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="bi bi-save me-1"></i> Update Schedule</button>
                </form>
            </div>
        </div>

        <!-- Holidays Section -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar2-x me-2 text-danger"></i>Upcoming Holidays (Closed Days)</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="row g-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="add_holiday">
                    <div class="col-5">
                        <input type="date" name="holiday_date" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-5">
                        <input type="text" name="holiday_name" class="form-control form-control-sm" placeholder="Holiday Name" required>
                    </div>
                    <div class="col-2">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>

                <ul class="list-group list-group-flush small">
                    <?php if (empty($holidays)): ?>
                        <li class="list-group-item text-muted text-center py-3">No upcoming holidays configured.</li>
                    <?php else: ?>
                        <?php foreach ($holidays as $hol): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <span class="fw-bold text-dark"><?= e($hol['holiday_date']) ?></span>: 
                                    <span><?= e($hol['holiday_name']) ?></span>
                                </div>
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Remove this holiday?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="section" value="delete_holiday">
                                    <input type="hidden" name="holiday_id" value="<?= $hol['id'] ?>">
                                    <button type="submit" class="btn btn-link text-danger p-0 border-0"><i class="bi bi-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
