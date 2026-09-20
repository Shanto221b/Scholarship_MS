<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

if (isLoggedIn()) {
    redirect('index.php');
}

// Role tab: ?role=… wins, otherwise the role remembered in a cookie, otherwise Student
$requested = $_POST['role'] ?? $_GET['role'] ?? $_COOKIE[COOKIE_LAST_ROLE] ?? 'Student';
$role  = $requested === 'Admin' ? 'Admin' : 'Student';
$login = (string) ($_COOKIE[COOKIE_LAST_USER] ?? '');
$remember = $login !== '';
$error = '';

if (isPost()) {
    $login    = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = ($_POST['remember'] ?? '') === '1';

    if (!verifyCsrfToken()) {
        $error = 'Your session expired. Please try again.';
    } elseif (($wait = login_wait_seconds()) > 0) {
        $error = "Too many failed attempts. Please wait $wait seconds and try again.";
    } elseif ($login === '' || $password === '') {
        $error = 'Please enter your ' . ($role === 'Student' ? 'username or email' : 'username') . ' and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.* FROM users u
             LEFT JOIN student s ON s.userid = u.userid
             WHERE u.username = ? OR s.email = ?
             LIMIT 1'
        );
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['passwordhash'])) {
            register_failed_login();
            $error = 'Incorrect username/email or password.';
        } elseif ($user['role'] !== $role) {
            $error = "This is a {$user['role']} account. Please select the \"{$user['role']}\" tab and log in again.";
        } elseif (!($profile = load_profile($pdo, $user))) {
            $error = 'This account has no ' . strtolower($role) . ' profile. Please contact the administrator.';
        } else {
            start_user_session($user, $profile);
            set_app_cookie(COOKIE_LAST_ROLE, $role, 365);
            if ($remember) {
                issue_remember_cookie($pdo, $user['userid']);
                set_app_cookie(COOKIE_LAST_USER, $user['username'], 365);
            } else {
                set_app_cookie(COOKIE_LAST_USER, '', 0);
            }
            finish('success', 'Welcome back, ' . $profile['name'] . '.', $role === 'Admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
        }
    }
    ajax_errors($error ? [$error] : []);
}

page_start('Login');
?>
<?php auth_open(); ?>
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-sub">Sign in to continue to your <?= $role === 'Admin' ? 'admin console' : 'student portal' ?>.</p>

        <?= render_flash() ?>

        <div class="role-switch" role="tablist">
            <a href="?role=Student" class="<?= $role === 'Student' ? 'active' : '' ?>" role="tab" aria-selected="<?= $role === 'Student' ? 'true' : 'false' ?>"><i class="bi bi-person"></i> Student</a>
            <a href="?role=Admin" class="<?= $role === 'Admin' ? 'active' : '' ?>" role="tab" aria-selected="<?= $role === 'Admin' ? 'true' : 'false' ?>"><i class="bi bi-shield-lock"></i> Admin</a>
        </div>

        <form method="POST" action="login.php" class="needs-validation" novalidate data-ajax>
            <?= error_list($error ? [$error] : []) ?>
            <?= csrfField() ?>
            <input type="hidden" name="role" value="<?= e($role) ?>">
            <div class="mb-3">
                <label class="form-label" for="login"><?= $role === 'Student' ? 'Username or email' : 'Admin username' ?></label>
                <input type="text" id="login" name="login" class="form-control" value="<?= e($login) ?>" maxlength="100" required <?= $login === '' ? 'autofocus' : '' ?> autocomplete="username">
                <div class="invalid-feedback">This field is required.</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" class="form-control" required <?= $login !== '' ? 'autofocus' : '' ?> autocomplete="current-password">
                    <button type="button" class="password-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <div class="remember-row">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" <?= $remember ? 'checked' : '' ?>>
                    <label class="form-check-label" for="remember">Keep me signed in for <?= (int) REMEMBER_DAYS ?> days</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100" data-busy="Signing in…">
                Sign in as <?= e($role) ?>
            </button>
        </form>

        <p class="auth-footer-text">
            New here? <a href="register.php?role=<?= e($role) ?>">Create <?= $role === 'Admin' ? 'an admin' : 'a student' ?> account</a>
        </p>

        <?php if (SHOW_DEMO_ACCOUNTS): ?>
        <div class="demo-box">
            <strong>Demo accounts</strong><br>
            <?php if ($role === 'Admin'): ?>
                <code>Admin1</code> / <code>adminpass1</code> &nbsp;(Admin1 to Admin5)
            <?php else: ?>
                <code>std1</code> / <code>stdpass1</code> &nbsp;or&nbsp; <code>karim@example.com</code>
            <?php endif; ?>
        </div>
        <?php endif; ?>
<?php auth_close(); ?>
<?php page_end(); ?>
