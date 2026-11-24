<?php
// ============================================================
// Authentication - Login Module
// ============================================================

require_once __DIR__ . '/../config/config.php';

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['role_name'] === 'Admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
    } elseif ($_SESSION['role_name'] === 'Staff') {
        header('Location: ' . BASE_URL . '/staff/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/user/dashboard.php');
    }
    exit();
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT u.*, r.role_name 
                               FROM `users` u 
                               JOIN `roles` r ON u.role_id = r.id 
                               WHERE u.username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                $error = 'Your account has been deactivated. Please contact the administrator.';
            } else {
                // Prevent Session Fixation
                session_regenerate_id(true);

                // Store in session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['role_name'] = $user['role_name'];

                // Update last login
                $updateStmt = $pdo->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = :id");
                $updateStmt->execute([':id' => $user['id']]);

                // Log Activity
                log_activity($user['id'], 'LOGIN_SUCCESS', "User {$user['username']} logged in successfully");

                // Redirect according to role
                if ($user['role_name'] === 'Admin') {
                    header('Location: ' . BASE_URL . '/admin/dashboard.php');
                } elseif ($user['role_name'] === 'Staff') {
                    header('Location: ' . BASE_URL . '/staff/dashboard.php');
                } else {
                    header('Location: ' . BASE_URL . '/user/dashboard.php');
                }
                exit();
            }
        } else {
            $error = 'Invalid credentials provided. Please check your username and password.';
            log_activity(null, 'LOGIN_FAILED', "Failed login attempt for username: {$username}");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100 py-4">
    <div class="container" style="max-width: 440px;">
        <div class="text-center mb-4">
            <div class="d-inline-flex p-3 bg-primary bg-opacity-10 text-primary rounded-circle mb-2">
                <i class="bi bi-person-badge fs-1"></i>
            </div>
            <h3 class="fw-bold text-dark"><?= e(APP_NAME) ?></h3>
            <p class="text-muted">Staff & Administrative Portal</p>
        </div>

        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body p-4">
                <?= display_flash() ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="username" name="username" value="<?= e($username) ?>" required autofocus placeholder="Enter your username">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="Enter your password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Portal
                    </button>

                    <div class="text-center mt-3 pt-2 border-top">
                        <span class="text-muted small">Are you a public citizen?</span>
                        <a href="<?= BASE_URL ?>/auth/register.php" class="small fw-bold text-decoration-none ms-1">
                            Register an Account
                        </a>
                    </div>
                </form>
            </div>
            <div class="card-footer bg-light border-0 py-3 text-center">
                <small class="text-muted">
                    <a href="<?= BASE_URL ?>/index.php" class="text-decoration-none text-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Return to Public Landing Page
                    </a>
                </small>
            </div>
        </div>

        <!-- Testing Credentials Helper Card -->
        <div class="card mt-3 border-0 bg-white shadow-sm">
            <div class="card-body py-2 px-3 small text-muted">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold text-dark"><i class="bi bi-key-fill text-warning me-1"></i> Default Demo Credentials:</span>
                    <span class="badge bg-light text-secondary border" style="font-size: 0.7rem;">Click to Auto-Fill</span>
                </div>
                <div class="d-flex flex-column gap-1 mt-2">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm flex-fill text-start py-1 px-2" onclick="fillCredentials('admin', 'password123')">
                            <i class="bi bi-shield-lock me-1"></i><span class="fw-semibold">Admin:</span> <code>admin</code>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill text-start py-1 px-2" onclick="fillCredentials('staff1', 'password123')">
                            <i class="bi bi-person-badge me-1"></i><span class="fw-semibold">Staff:</span> <code>staff1</code>
                        </button>
                    </div>
                    <button type="button" class="btn btn-outline-info btn-sm text-start py-1 px-2" onclick="fillCredentials('citizen1', 'password123')">
                        <i class="bi bi-person me-1"></i><span class="fw-semibold">Citizen:</span> <code>citizen1</code> / <code>password123</code>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function fillCredentials(username, password) {
            const userInput = document.getElementById('username');
            const passInput = document.getElementById('password');
            if (userInput && passInput) {
                userInput.value = username;
                passInput.value = password;
                userInput.focus();
                
                // Visual feedback highlight
                userInput.classList.add('is-valid');
                passInput.classList.add('is-valid');
                setTimeout(() => {
                    userInput.classList.remove('is-valid');
                    passInput.classList.remove('is-valid');
                }, 1500);
            }
        }
    </script>
</body>
</html>
