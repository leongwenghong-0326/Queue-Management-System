<?php
// ============================================================
// Admin - Counter Management & Staff Assignment Module
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pageTitle = 'Manage Service Counters';
$pdo = Database::getConnection();

// Handle Actions (Create / Update / Assign Services / Assign Staff / Toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['counter_name'] ?? '');
        $code = trim($_POST['counter_code'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $services = $_POST['services'] ?? [];

        if (empty($name) || empty($code)) {
            set_flash('danger', 'Counter Name and Code are required.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM `counters` WHERE `counter_code` = :c LIMIT 1");
            $chk->execute([':c' => $code]);
            if ($chk->fetch()) {
                set_flash('danger', "Counter code '{$code}' already exists.");
            } else {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO `counters` (`counter_name`, `counter_code`, `location`, `status`, `created_at`) 
                                           VALUES (:n, :c, :l, 'active', NOW())");
                    $stmt->execute([':n' => $name, ':c' => $code, ':l' => $location]);
                    $counterId = (int)$pdo->lastInsertId();

                    // Insert supported services
                    if (!empty($services)) {
                        $svcStmt = $pdo->prepare("INSERT INTO `counter_services` (`counter_id`, `service_id`) VALUES (:cid, :sid)");
                        foreach ($services as $sid) {
                            $svcStmt->execute([':cid' => $counterId, ':sid' => (int)$sid]);
                        }
                    }

                    $pdo->commit();
                    log_activity($_SESSION['user_id'], 'COUNTER_CREATED', "Created counter {$name} [{$code}]");
                    set_flash('success', "Counter '{$name}' created successfully.");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    set_flash('danger', "Failed to create counter: " . $e->getMessage());
                }
                header('Location: ' . BASE_URL . '/admin/counters/index.php');
                exit();
            }
        }
    } elseif ($action === 'update') {
        $counterId = (int)($_POST['counter_id'] ?? 0);
        $name = trim($_POST['counter_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $services = $_POST['services'] ?? [];
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($name)) {
            set_flash('danger', 'Counter Name cannot be empty.');
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE `counters` SET `counter_name` = :n, `location` = :l, `status` = :s WHERE `id` = :id");
                $stmt->execute([':n' => $name, ':l' => $location, ':s' => $status, ':id' => $counterId]);

                // Sync counter services
                $pdo->prepare("DELETE FROM `counter_services` WHERE `counter_id` = :cid")->execute([':cid' => $counterId]);
                if (!empty($services)) {
                    $svcStmt = $pdo->prepare("INSERT INTO `counter_services` (`counter_id`, `service_id`) VALUES (:cid, :sid)");
                    foreach ($services as $sid) {
                        $svcStmt->execute([':cid' => $counterId, ':sid' => (int)$sid]);
                    }
                }

                $pdo->commit();
                log_activity($_SESSION['user_id'], 'COUNTER_UPDATED', "Updated counter ID: {$counterId}");
                set_flash('success', 'Counter details updated successfully.');
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('danger', 'Failed to update counter: ' . $e->getMessage());
            }
            header('Location: ' . BASE_URL . '/admin/counters/index.php');
            exit();
        }
    } elseif ($action === 'assign_staff') {
        $counterId = (int)($_POST['counter_id'] ?? 0);
        $staffId = (int)($_POST['staff_id'] ?? 0);

        if ($counterId > 0 && $staffId > 0) {
            $pdo->beginTransaction();
            try {
                // End existing active assignment for this counter or staff
                $pdo->prepare("UPDATE `staff_counter_assignments` SET `status` = 'ended', `ended_at` = NOW() 
                               WHERE (`counter_id` = :cid OR `staff_id` = :sid) AND `status` = 'active'")
                    ->execute([':cid' => $counterId, ':sid' => $staffId]);

                // Create fresh assignment
                $stmt = $pdo->prepare("INSERT INTO `staff_counter_assignments` (`staff_id`, `counter_id`, `assigned_at`, `status`) 
                                       VALUES (:sid, :cid, NOW(), 'active')");
                $stmt->execute([':sid' => $staffId, ':cid' => $counterId]);

                $pdo->commit();
                log_activity($_SESSION['user_id'], 'STAFF_ASSIGNED_COUNTER', "Assigned staff ID {$staffId} to counter ID {$counterId}");
                set_flash('success', 'Staff member assigned to counter successfully.');
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('danger', 'Assignment failed: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/admin/counters/index.php');
        exit();
    } elseif ($action === 'unassign_staff') {
        $counterId = (int)($_POST['counter_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE `staff_counter_assignments` SET `status` = 'ended', `ended_at` = NOW() WHERE `counter_id` = :cid AND `status` = 'active'");
        $stmt->execute([':cid' => $counterId]);
        log_activity($_SESSION['user_id'], 'STAFF_UNASSIGNED_COUNTER', "Unassigned staff from counter ID {$counterId}");
        set_flash('success', 'Staff unassigned from counter.');
        header('Location: ' . BASE_URL . '/admin/counters/index.php');
        exit();
    }
}

// Fetch all counters with assigned staff and services
$counters = $pdo->query("SELECT c.*,
    u.id as staff_id,
    u.name as staff_name,
    u.username as staff_username,
    (SELECT GROUP_CONCAT(s.service_code SEPARATOR ', ') 
     FROM `counter_services` cs 
     JOIN `services` s ON cs.service_id = s.id 
     WHERE cs.counter_id = c.id) as service_codes,
    (SELECT GROUP_CONCAT(cs.service_id) 
     FROM `counter_services` cs 
     WHERE cs.counter_id = c.id) as service_ids_csv
FROM `counters` c
LEFT JOIN `staff_counter_assignments` sca ON c.id = sca.counter_id AND sca.status = 'active'
LEFT JOIN `users` u ON sca.staff_id = u.id
ORDER BY c.id ASC")->fetchAll();

// Fetch active services and active staff for dropdowns
$activeServices = $pdo->query("SELECT * FROM `services` WHERE `status` = 'active' ORDER BY service_code ASC")->fetchAll();
$activeStaff = $pdo->query("SELECT * FROM `users` WHERE `role_id` = 2 AND `status` = 'active' ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Service Counters & Assignments</h4>
        <p class="text-muted small mb-0">Manage service stations, assign handling services, and designate staff officers.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCounterModal">
        <i class="bi bi-window-plus me-1"></i> Add Service Counter
    </button>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Code</th>
                        <th>Counter Name</th>
                        <th>Location</th>
                        <th>Authorized Services</th>
                        <th>Assigned Staff Officer</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($counters as $c): ?>
                        <tr>
                            <td class="ps-3 fw-bold"><code><?= e($c['counter_code']) ?></code></td>
                            <td class="fw-semibold text-dark"><?= e($c['counter_name']) ?></td>
                            <td class="small text-muted"><?= e($c['location'] ?? 'General Hall') ?></td>
                            <td>
                                <?php if (!empty($c['service_codes'])): ?>
                                    <span class="badge bg-secondary"><?= e($c['service_codes']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">No services assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['staff_name'])): ?>
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-person-badge text-success me-1"></i>
                                        <span class="fw-semibold small"><?= e($c['staff_name']) ?></span>
                                        <span class="text-muted small ms-1">(<?= e($c['staff_username']) ?>)</span>
                                        <form method="POST" action="" class="d-inline ms-2" onsubmit="return confirm('Unassign officer from counter?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="unassign_staff">
                                            <input type="hidden" name="counter_id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-link text-danger p-0 border-0" title="Unassign">
                                                <i class="bi bi-x-circle-fill"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $c['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= ucfirst($c['status']) ?>
                                </span>
                            </td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-primary assign-staff-btn me-1"
                                    data-counter-id="<?= $c['id'] ?>"
                                    data-counter-name="<?= e($c['counter_name']) ?>"
                                    data-staff-id="<?= $c['staff_id'] ?? '' ?>"
                                    data-bs-toggle="modal" data-bs-target="#assignStaffModal">
                                    <i class="bi bi-person-check"></i> Assign
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-counter-btn"
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= e($c['counter_name']) ?>"
                                    data-location="<?= e($c['location']) ?>"
                                    data-services="<?= e($c['service_ids_csv'] ?? '') ?>"
                                    data-status="<?= $c['status'] ?>"
                                    data-bs-toggle="modal" data-bs-target="#editCounterModal">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Create Counter -->
<div class="modal fade" id="createCounterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Create Service Counter</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Counter Name</label>
                            <input type="text" name="counter_name" class="form-control" required placeholder="e.g. Counter 5">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Counter Code</label>
                            <input type="text" name="counter_code" class="form-control" required placeholder="e.g. C-05">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Physical Location</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g. Ground Floor - Section B">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Supported Services</label>
                        <div class="border rounded p-2 bg-light">
                            <?php foreach ($activeServices as $asvc): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="services[]" value="<?= $asvc['id'] ?>" id="create_svc_<?= $asvc['id'] ?>">
                                    <label class="form-check-label" for="create_svc_<?= $asvc['id'] ?>">
                                        <strong>[<?= e($asvc['service_code']) ?>]</strong> <?= e($asvc['service_name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Counter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Assign Staff -->
<div class="modal fade" id="assignStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_staff">
                <input type="hidden" name="counter_id" id="assign_counter_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Assign Staff to <span id="assign_counter_name"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Staff Officer</label>
                        <select name="staff_id" id="assign_staff_id" class="form-select" required>
                            <option value="">-- Choose Officer --</option>
                            <?php foreach ($activeStaff as $astaff): ?>
                                <option value="<?= $astaff['id'] ?>"><?= e($astaff['name']) ?> (<?= e($astaff['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted mt-1 d-block">Assigning an officer will automatically transfer active session context safely.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Counter -->
<div class="modal fade" id="editCounterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="counter_id" id="edit_counter_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Counter</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Counter Name</label>
                        <input type="text" name="counter_name" id="edit_counter_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Location</label>
                        <input type="text" name="location" id="edit_counter_location" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Supported Services</label>
                        <div class="border rounded p-2 bg-light">
                            <?php foreach ($activeServices as $asvc): ?>
                                <div class="form-check">
                                    <input class="form-check-input edit-service-checkbox" type="checkbox" name="services[]" value="<?= $asvc['id'] ?>" id="edit_svc_<?= $asvc['id'] ?>">
                                    <label class="form-check-label" for="edit_svc_<?= $asvc['id'] ?>">
                                        <strong>[<?= e($asvc['service_code']) ?>]</strong> <?= e($asvc['service_name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" id="edit_counter_status" class="form-select">
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
document.querySelectorAll('.assign-staff-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('assign_counter_id').value = this.dataset.counterId;
        document.getElementById('assign_counter_name').textContent = this.dataset.counterName;
        document.getElementById('assign_staff_id').value = this.dataset.staffId || '';
    });
});

document.querySelectorAll('.edit-counter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit_counter_id').value = this.dataset.id;
        document.getElementById('edit_counter_name').value = this.dataset.name;
        document.getElementById('edit_counter_location').value = this.dataset.location;
        document.getElementById('edit_counter_status').value = this.dataset.status;

        // Uncheck all service checkboxes first
        document.querySelectorAll('.edit-service-checkbox').forEach(cb => cb.checked = false);
        const svcIds = (this.dataset.services || '').split(',').map(s => s.trim());
        svcIds.forEach(id => {
            const cb = document.getElementById('edit_svc_' + id);
            if (cb) cb.checked = true;
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
