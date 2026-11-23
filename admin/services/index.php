<?php
// ============================================================
// Admin - Service Management Module
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pageTitle = 'Manage Services';
$pdo = Database::getConnection();

// Handle Actions (Create / Update / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = strtoupper(trim($_POST['service_code'] ?? ''));
        $name = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $duration = max(1, (int)($_POST['default_service_duration'] ?? 10));
        $capacity = max(0, (int)($_POST['maximum_queue_capacity'] ?? 100));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($code) || empty($name)) {
            set_flash('danger', 'Service Code and Service Name are required.');
        } else {
            // Check code uniqueness
            $chk = $pdo->prepare("SELECT id FROM `services` WHERE `service_code` = :c LIMIT 1");
            $chk->execute([':c' => $code]);
            if ($chk->fetch()) {
                set_flash('danger', "Service code '{$code}' already exists.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO `services` (`service_code`, `service_name`, `description`, `default_service_duration`, `maximum_queue_capacity`, `status`, `created_at`) 
                                       VALUES (:c, :n, :d, :dur, :cap, :s, NOW())");
                $stmt->execute([
                    ':c' => $code,
                    ':n' => $name,
                    ':d' => $description,
                    ':dur' => $duration,
                    ':cap' => $capacity,
                    ':s' => $status
                ]);
                log_activity($_SESSION['user_id'], 'SERVICE_CREATED', "Created service: [{$code}] {$name}");
                set_flash('success', "Service '{$name}' created successfully.");
                header('Location: ' . BASE_URL . '/admin/services/index.php');
                exit();
            }
        }
    } elseif ($action === 'update') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $name = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $duration = max(1, (int)($_POST['default_service_duration'] ?? 10));
        $capacity = max(0, (int)($_POST['maximum_queue_capacity'] ?? 100));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($name)) {
            set_flash('danger', 'Service Name cannot be empty.');
        } else {
            $stmt = $pdo->prepare("UPDATE `services` SET `service_name` = :n, `description` = :d, `default_service_duration` = :dur, `maximum_queue_capacity` = :cap, `status` = :s WHERE `id` = :id");
            $stmt->execute([
                ':n' => $name,
                ':d' => $description,
                ':dur' => $duration,
                ':cap' => $capacity,
                ':s' => $status,
                ':id' => $serviceId
            ]);
            log_activity($_SESSION['user_id'], 'SERVICE_UPDATED', "Updated service ID: {$serviceId}");
            set_flash('success', 'Service updated successfully.');
            header('Location: ' . BASE_URL . '/admin/services/index.php');
            exit();
        }
    } elseif ($action === 'toggle_status') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE `services` SET `status` = IF(`status` = 'active', 'inactive', 'active') WHERE `id` = :id");
        $stmt->execute([':id' => $serviceId]);
        log_activity($_SESSION['user_id'], 'SERVICE_STATUS_TOGGLED', "Toggled status for service ID: {$serviceId}");
        set_flash('success', 'Service status updated.');
        header('Location: ' . BASE_URL . '/admin/services/index.php');
        exit();
    }
}

// Fetch all services with active counters count
$services = $pdo->query("SELECT s.*, 
    (SELECT COUNT(*) FROM `counter_services` cs JOIN `counters` c ON cs.counter_id = c.id WHERE cs.service_id = s.id AND c.status = 'active') AS active_counters_count,
    (SELECT COUNT(*) FROM `queues` q WHERE q.service_id = s.id AND q.queue_date = CURDATE()) AS today_tickets_count
FROM `services` s 
ORDER BY s.id ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Service Categories & Capacities</h4>
        <p class="text-muted small mb-0">Configure ticket letter prefixes, expected durations, and daily capacity limits.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createServiceModal">
        <i class="bi bi-plus-circle me-1"></i> Add New Service
    </button>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Code</th>
                        <th>Service Name</th>
                        <th>Default Duration</th>
                        <th>Daily Capacity</th>
                        <th>Today's Volume</th>
                        <th>Assigned Counters</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $s): ?>
                        <tr>
                            <td class="ps-3">
                                <span class="badge bg-dark fs-6 px-2"><?= e($s['service_code']) ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= e($s['service_name']) ?></div>
                                <small class="text-muted"><?= e($s['description']) ?></small>
                            </td>
                            <td><i class="bi bi-clock me-1 text-muted"></i><?= $s['default_service_duration'] ?> mins</td>
                            <td>
                                <?= $s['maximum_queue_capacity'] > 0 ? $s['maximum_queue_capacity'] . ' tickets' : '<span class="text-muted">Unlimited</span>' ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= $s['today_tickets_count'] ?> registered</span>
                            </td>
                            <td>
                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                    <?= $s['active_counters_count'] ?> active counters
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $s['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= ucfirst($s['status']) ?>
                                </span>
                            </td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-service-btn"
                                    data-id="<?= $s['id'] ?>"
                                    data-code="<?= e($s['service_code']) ?>"
                                    data-name="<?= e($s['service_name']) ?>"
                                    data-desc="<?= e($s['description']) ?>"
                                    data-duration="<?= $s['default_service_duration'] ?>"
                                    data-capacity="<?= $s['maximum_queue_capacity'] ?>"
                                    data-status="<?= $s['status'] ?>"
                                    data-bs-toggle="modal" data-bs-target="#editServiceModal">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <form method="POST" action="" class="d-inline ms-1" onsubmit="return confirm('Toggle status for this service?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $s['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                        <i class="bi <?= $s['status'] === 'active' ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Create Service -->
<div class="modal fade" id="createServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Create New Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Prefix Code</label>
                            <input type="text" name="service_code" class="form-control" maxlength="5" required placeholder="e.g. E" style="text-transform:uppercase">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Service Name</label>
                            <input type="text" name="service_name" class="form-control" required placeholder="e.g. VIP Consultation">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Brief scope of service"></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Default Duration (mins)</label>
                            <input type="number" name="default_service_duration" class="form-control" value="10" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Max Daily Capacity</label>
                            <input type="number" name="maximum_queue_capacity" class="form-control" value="100" min="0">
                            <small class="text-muted">0 for unlimited</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Service -->
<div class="modal fade" id="editServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="service_id" id="edit_service_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Code</label>
                        <input type="text" id="edit_service_code" class="form-control bg-light" disabled>
                        <small class="text-muted">Service code prefix cannot be altered to maintain queue sequence continuity.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Name</label>
                        <input type="text" name="service_name" id="edit_service_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Default Duration (mins)</label>
                            <input type="number" name="default_service_duration" id="edit_duration" class="form-control" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Max Daily Capacity</label>
                            <input type="number" name="maximum_queue_capacity" id="edit_capacity" class="form-control" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-service-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit_service_id').value = this.dataset.id;
        document.getElementById('edit_service_code').value = this.dataset.code;
        document.getElementById('edit_service_name').value = this.dataset.name;
        document.getElementById('edit_description').value = this.dataset.desc;
        document.getElementById('edit_duration').value = this.dataset.duration;
        document.getElementById('edit_capacity').value = this.dataset.capacity;
        document.getElementById('edit_status').value = this.dataset.status;
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
