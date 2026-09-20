<?php
/**
 * One-click installer: checks the server, creates the database, tables,
 * triggers, functions, procedures and demo data from database/scholarhub_db.sql.
 */
require_once __DIR__ . '/config/db.php';      // $pdo is NOT opened for the installer
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

const REQUIRED_TABLES = ['users', 'department', 'admin', 'student', 'scholarship', 'application', 'payment', 'remember_token'];

/** Runs a .sql file that may contain DELIMITER blocks (triggers, procedures). */
function run_sql_file(PDO $db, string $file): int
{
    $lines     = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($file)));
    $delimiter = ';';
    $buffer    = '';
    $count     = 0;

    $execute = function (string $sql) use ($db, &$count): void {
        $sql = trim($sql);
        if ($sql === '' || preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $sql)) {
            return;   // the installer selects the database itself (DB_NAME)
        }
        $db->exec($sql);
        $count++;
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#'))) {
            continue;
        }
        if (preg_match('/^DELIMITER\s+(\S+)$/i', $trimmed, $m)) {
            $delimiter = $m[1];
            continue;
        }
        $buffer .= $line . "\n";
        if (substr(rtrim($line), -strlen($delimiter)) === $delimiter) {
            $execute(substr(rtrim($buffer), 0, -strlen($delimiter)));
            $buffer = '';
        }
    }
    $execute($buffer);
    return $count;
}

$checks  = [];
$server  = null;
$error   = '';
$success = '';
$dbName  = preg_replace('/[^A-Za-z0-9_]/', '', DB_NAME);

$checks[] = ['PHP version 8.0 or newer', version_compare(PHP_VERSION, '8.0.0', '>='), 'Found PHP ' . PHP_VERSION];
$checks[] = ['PDO MySQL extension (pdo_mysql)', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'Enabled' : 'Enable extension=pdo_mysql in php.ini'];
$checks[] = ['Multibyte string extension (mbstring)', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'Enabled' : 'Enable extension=mbstring in php.ini'];
$checks[] = ['Uploads folder writable (profile photos)', is_writable(__DIR__ . '/uploads/avatars'), is_writable(__DIR__ . '/uploads/avatars') ? 'uploads/avatars' : 'Make uploads/avatars writable'];
$checks[] = ['SQL file present', is_file(__DIR__ . '/database/scholarhub_db.sql'), 'database/scholarhub_db.sql'];

try {
    $server = db_connect(false);
    $ver = $server->query('SELECT VERSION()')->fetchColumn();
    $checks[] = ['Connect to MySQL / MariaDB server', true, 'Connected (' . $ver . ')'];
} catch (Throwable $t) {
    $checks[] = ['Connect to MySQL / MariaDB server', false, 'Start MySQL in XAMPP and check config/config.php — ' . $t->getMessage()];
}

function installed_table_count(?PDO $server, string $dbName): int
{
    if (!$server) {
        return 0;
    }
    $in = implode(',', array_fill(0, count(REQUIRED_TABLES), '?'));
    $q = $server->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name IN ($in)");
    $q->execute(array_merge([$dbName], REQUIRED_TABLES));
    return (int) $q->fetchColumn();
}

$installed = installed_table_count($server, $dbName) === count(REQUIRED_TABLES);
$isAdmin   = isLoggedIn() && ($_SESSION['role'] ?? '') === 'Admin';
$ready     = !in_array(false, array_column($checks, 1), true);

if (isPost()) {
    if (!verifyCsrfToken()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (!$ready) {
        $error = 'Fix the failed checks above first.';
    } elseif ($installed && !$isAdmin) {
        $error = 'The database is already installed. Only a logged-in admin can reset it.';
    } elseif ($installed && ($_POST['confirm_reset'] ?? '') !== 'RESET') {
        $error = 'Type RESET in the box to confirm deleting all data.';
    } else {
        try {
            $server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $server->exec("USE `$dbName`");
            $n = run_sql_file($server, __DIR__ . '/database/scholarhub_db.sql');
            $wasReset = $installed;
            $installed = installed_table_count($server, $dbName) === count(REQUIRED_TABLES);
            if ($wasReset) {
                $_SESSION = [];
            }
            $success = "Database installed successfully ($n statements executed).";
        } catch (Throwable $t) {
            $error = 'Installation failed: ' . $t->getMessage();
        }
    }
}

page_start('Installer');
?>
<?php auth_open(true); ?>
    <h1 class="auth-title">Setup &amp; installation</h1>
    <p class="auth-sub">Database <strong><?= e($dbName) ?></strong> on <?= e(DB_HOST) ?></p>
    <div class="install-card">
    <ul class="list-unstyled mb-4 install-checks">
        <?php foreach ($checks as [$label, $ok, $detail]): ?>
        <li class="d-flex gap-2 align-items-start mb-2">
            <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> mt-1"></i>
            <div><strong><?= e($label) ?></strong><br><small class="text-muted"><?= e($detail) ?></small></div>
        </li>
        <?php endforeach; ?>
        <li class="d-flex gap-2 align-items-start">
            <i class="bi <?= $installed ? 'bi-check-circle-fill text-success' : 'bi-dash-circle text-warning' ?> mt-1"></i>
            <div><strong>Database tables</strong><br><small class="text-muted"><?= $installed ? 'Installed: all ' . count(REQUIRED_TABLES) . ' tables found' : 'Not installed yet' ?></small></div>
        </li>
    </ul>

    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><div><?= e($error) ?></div></div><?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle"></i><div><?= e($success) ?></div></div>
        <div class="demo-box mb-3">
            <strong>Log in with the demo accounts:</strong><br>
            Admin: <code>Admin1</code> / <code>adminpass1</code> &nbsp;&middot;&nbsp;
            Student: <code>std1</code> / <code>stdpass1</code>
        </div>
        <a href="login.php" class="btn btn-primary w-100 py-2">Go to sign in</a>
    <?php elseif (!$installed): ?>
        <form method="POST">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-primary w-100 py-2" data-busy="Installing…" <?= $ready ? '' : 'disabled' ?>>
                Install database
            </button>
            <p class="form-text mt-2">Creates the tables, triggers, functions, stored procedures and demo data.</p>
        </form>
    <?php else: ?>
        <a href="login.php" class="btn btn-primary w-100 py-2 mb-3">Everything is ready. Go to sign in</a>
        <?php if ($isAdmin): ?>
        <form method="POST" class="border rounded-2 p-3" data-confirm="This deletes ALL data and restores the demo data. Continue?">
            <?= csrfField() ?>
            <label class="form-label text-danger" for="confirm_reset"><i class="bi bi-exclamation-triangle"></i> Reset database (deletes all data)</label>
            <div class="d-flex gap-2">
                <input type="text" id="confirm_reset" name="confirm_reset" class="form-control" placeholder="Type RESET">
                <button type="submit" class="btn btn-outline-danger">Reset</button>
            </div>
        </form>
        <?php else: ?>
        <p class="form-text text-center">To reset the database, log in as an admin and open this page again.</p>
        <?php endif; ?>
    <?php endif; ?>
    </div>
<?php auth_close(); ?>
<?php page_end(); ?>
