<?php
require_once __DIR__ . '/config.php';

/**
 * Works out the project's URL path automatically (e.g. "/scholarhub/"),
 * so the project works no matter what the folder inside htdocs is called.
 */
function detect_base_url(): string
{
    $appRoot    = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $script     = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($appRoot !== '' && $script !== '' && stripos($script, $appRoot) === 0) {
        $relative = substr($script, strlen($appRoot));            // e.g. /admin/dashboard.php
        $tail     = substr($scriptName, -strlen($relative));
        if ($relative !== '' && strcasecmp($tail, $relative) === 0) {
            $base = substr($scriptName, 0, strlen($scriptName) - strlen($relative));
            return rtrim($base, '/') . '/';
        }
    }
    return '/';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', detect_base_url());
}

function db_error_page(string $title, string $body): void
{
    http_response_code(500);
    $base = BASE_URL;
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>body{font-family:system-ui,Segoe UI,sans-serif;background:#f3f7f4;color:#1c2b22;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px}
.box{background:#fff;max-width:560px;border-radius:8px;padding:32px;border:1px solid #dbe6de}h1{font-size:20px;margin:0 0 12px;color:#b91c1c}li{margin:6px 0}a{color:#1b5e3a}</style></head>
<body><div class="box"><h1>{$title}</h1>{$body}<p><a href="{$base}install.php">Open the installer</a></p></div></body></html>
HTML;
    exit;
}

function db_connect(bool $withDatabase = true): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4'
         . ($withDatabase ? ';dbname=' . DB_NAME : '');
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // keep MySQL's NOW() in the same time zone as PHP (config.php)
    $db->exec("SET time_zone = '" . date('P') . "'");
    return $db;
}

$pdo = null;
$isInstaller = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'install.php';

if (!$isInstaller) {
    try {
        $pdo = db_connect(true);
        $pdo->query('SELECT 1 FROM users LIMIT 1');   // are the tables there?
        $pdo->query('SELECT applicationfee FROM scholarship LIMIT 1');   // newest schema (payments)?
        $pdo->query('SELECT 1 FROM payment LIMIT 1');
    } catch (PDOException $e) {
        $code = (string) $e->getCode();
        if ($code === '1049' || $code === '42S02' || $code === '42S22') {
            // Database or tables missing -> send the user to the installer.
            header('Location: ' . BASE_URL . 'install.php');
            exit;
        }
        db_error_page(
            'Cannot connect to MySQL',
            '<p>The application could not connect to the database server.</p><ul>'
            . '<li>Open the XAMPP Control Panel and make sure <b>MySQL</b> is <b>Running</b>.</li>'
            . '<li>Check the username/password in <code>config/config.php</code>.</li></ul>'
            . '<p style="color:#6b7280;font-size:13px">Details: ' . htmlspecialchars($e->getMessage()) . '</p>'
        );
    }
}
