<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    session_name('SCHOLARHUB');
    session_start();
}

/* ---------------- cookie names ---------------- */
const COOKIE_REMEMBER  = 'SH_REMEMBER';   // "Remember me" login token (selector:validator)
const COOKIE_LAST_ROLE = 'SH_LAST_ROLE';  // last role used on the login page (Student / Admin)
const COOKIE_LAST_USER = 'SH_LAST_USER';  // username to pre-fill when "Remember me" was ticked
const COOKIE_NOTICE    = 'SH_COOKIE_OK';  // cookie notice dismissed (set by JavaScript)
const COOKIE_THEME     = 'SH_THEME';      // light / dark mode choice (set by JavaScript)

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Sets a cookie scoped to this project's folder. $days = 0 deletes it. */
function set_app_cookie(string $name, string $value, int $days, bool $httpOnly = true): void
{
    setcookie($name, $value, [
        'expires'  => $days > 0 ? time() + $days * 86400 : time() - 3600,
        'path'     => BASE_URL,
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    if ($days > 0) {
        $_COOKIE[$name] = $value;
    } else {
        unset($_COOKIE[$name]);
    }
}

/* ---------------- output / navigation helpers ---------------- */

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $icon = $f['type'] === 'success' ? 'check-circle' : ($f['type'] === 'danger' ? 'exclamation-circle' : 'info-circle');
        $html .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show" role="alert">'
               . '<i class="bi bi-' . $icon . '"></i><div>' . e($f['message']) . '</div>'
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

function money($amount): string
{
    return '<span class="taka">&#2547;</span>' . number_format((float) $amount, 2);
}

function fmt_date($date): string
{
    return $date ? date('d M Y', strtotime((string) $date)) : '&mdash;';
}

function status_badge(string $status): string
{
    return '<span class="status-badge status-' . e(strtolower($status)) . '">' . e($status) . '</span>';
}

/* ---------------- AJAX helpers ---------------- */

/** True when the request was sent by our JavaScript (fetch with X-Requested-With). */
function is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ends a POST action.
 *  - Normal request: stores a flash message and redirects.
 *  - AJAX request:   returns JSON. If $navigate is true the browser is sent to
 *                    $path (and the flash message shows there); otherwise the
 *                    page stays and JavaScript shows the message.
 */
function finish(string $type, string $message, string $path, bool $navigate = true, array $extra = []): void
{
    if (is_ajax()) {
        $data = ['ok' => $type === 'success', 'type' => $type, 'message' => $message] + $extra;
        if ($navigate) {
            flash($type, $message);
            $data['redirect'] = BASE_URL . ltrim($path, '/');
            unset($data['message']);
        }
        json_response($data);
    }
    flash($type, $message);
    redirect($path);
}

/** For AJAX requests: send validation errors back as JSON (normal requests continue). */
function ajax_errors(array $errors): void
{
    if ($errors && is_ajax()) {
        json_response(['ok' => false, 'errors' => array_values($errors)]);
    }
}

/* ---------------- authentication ---------------- */

function isLoggedIn(): bool
{
    return isset($_SESSION['userid'], $_SESSION['role']);
}

function deny(string $message, string $path): void
{
    if (is_ajax()) {
        json_response(['ok' => false, 'message' => $message, 'redirect' => BASE_URL . $path], 401);
    }
    flash('warning', $message);
    redirect($path);
}

function requireAdmin(): void
{
    if (!isLoggedIn()) {
        deny('Please log in to continue.', 'login.php?role=Admin');
    }
    if ($_SESSION['role'] !== 'Admin') {
        is_ajax() ? json_response(['ok' => false, 'message' => 'Admins only.'], 403) : redirect('student/dashboard.php');
    }
}

function requireStudent(): void
{
    if (!isLoggedIn()) {
        deny('Please log in to continue.', 'login.php');
    }
    if ($_SESSION['role'] !== 'Student') {
        is_ajax() ? json_response(['ok' => false, 'message' => 'Students only.'], 403) : redirect('admin/dashboard.php');
    }
}

/** Loads the admin / student row that belongs to a user account. */
function load_profile(PDO $pdo, array $user): array|false
{
    $sql = $user['role'] === 'Admin'
        ? 'SELECT adminid, name, photo FROM admin WHERE userid = ?'
        : 'SELECT studentid, name, photo FROM student WHERE userid = ?';
    $q = $pdo->prepare($sql);
    $q->execute([$user['userid']]);
    return $q->fetch();
}

/** Puts a verified user into the session. */
function start_user_session(array $user, array $profile): void
{
    session_regenerate_id(true);
    unset($_SESSION['login_fails'], $_SESSION['login_lock_until'], $_SESSION['csrf_token']);
    $_SESSION['userid']   = $user['userid'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['name']     = $profile['name'];
    $_SESSION['photo']    = $profile['photo'] ?? null;
    global $pdo;
    if ($pdo instanceof PDO) {
        $pdo->prepare('UPDATE users SET lastlogin = NOW() WHERE userid = ?')->execute([$user['userid']]);
    }
    if ($user['role'] === 'Admin') {
        $_SESSION['adminid'] = $profile['adminid'];
    } else {
        $_SESSION['studentid'] = $profile['studentid'];
    }
}

/* ---------------- "Remember me" cookie ---------------- */

/** Creates a new remember-me token: cookie gets selector:validator, DB gets sha256(validator). */
function issue_remember_cookie(PDO $pdo, string $userid): void
{
    $selector  = bin2hex(random_bytes(12));   // 24 chars
    $validator = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM remember_token WHERE expires < NOW()')->execute();
    $pdo->prepare('INSERT INTO remember_token (selector, userid, validatorhash, expires) VALUES (?, ?, ?, ?)')
        ->execute([$selector, $userid, hash('sha256', $validator), date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400)]);
    set_app_cookie(COOKIE_REMEMBER, $selector . ':' . $validator, REMEMBER_DAYS);
}

function clear_remember_cookie(?PDO $pdo): void
{
    $raw = (string) ($_COOKIE[COOKIE_REMEMBER] ?? '');
    if ($pdo && str_contains($raw, ':')) {
        $pdo->prepare('DELETE FROM remember_token WHERE selector = ?')->execute([explode(':', $raw, 2)[0]]);
    }
    set_app_cookie(COOKIE_REMEMBER, '', 0);
}

/** Logs the user in from a valid remember-me cookie (token is rotated on every use). */
function try_remember_login(PDO $pdo): void
{
    $raw = (string) ($_COOKIE[COOKIE_REMEMBER] ?? '');
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        if ($raw !== '') {
            set_app_cookie(COOKIE_REMEMBER, '', 0);
        }
        return;
    }
    [, $selector, $validator] = $m;

    $q = $pdo->prepare('SELECT * FROM remember_token WHERE selector = ? AND expires > NOW()');
    $q->execute([$selector]);
    $token = $q->fetch();

    if (!$token || !hash_equals($token['validatorhash'], hash('sha256', $validator))) {
        if ($token) {
            // right selector + wrong validator = the cookie was probably stolen: log out everywhere
            $pdo->prepare('DELETE FROM remember_token WHERE userid = ?')->execute([$token['userid']]);
        }
        set_app_cookie(COOKIE_REMEMBER, '', 0);
        return;
    }

    $u = $pdo->prepare('SELECT * FROM users WHERE userid = ?');
    $u->execute([$token['userid']]);
    $user = $u->fetch();
    $profile = $user ? load_profile($pdo, $user) : false;

    $pdo->prepare('DELETE FROM remember_token WHERE selector = ?')->execute([$selector]);
    if (!$user || !$profile) {
        set_app_cookie(COOKIE_REMEMBER, '', 0);
        return;
    }
    start_user_session($user, $profile);
    issue_remember_cookie($pdo, $user['userid']);
}

if (!isLoggedIn() && isset($_COOKIE[COOKIE_REMEMBER]) && isset($pdo) && $pdo instanceof PDO) {
    try_remember_login($pdo);
}

/** Simple brute-force protection: 5 wrong passwords -> wait 60 seconds. */
function login_wait_seconds(): int
{
    return max(0, (int) ($_SESSION['login_lock_until'] ?? 0) - time());
}

function register_failed_login(): void
{
    $_SESSION['login_fails'] = (int) ($_SESSION['login_fails'] ?? 0) + 1;
    if ($_SESSION['login_fails'] >= 5) {
        $_SESSION['login_lock_until'] = time() + 60;
        $_SESSION['login_fails'] = 0;
    }
}

/* ---------------- CSRF protection ---------------- */

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrfToken(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return isset($_SESSION['csrf_token']) && $token !== '' && hash_equals($_SESSION['csrf_token'], $token);
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
