<?php
/**
 * Shared page layout.
 *   page_start('Title', 'Admin', 'dashboard');  ...page body...  page_end(true);
 *   page_start('Login');                         ...full-page body... page_end();
 */

/** Theme chosen by the user (cookie), or '' to follow the operating system. */
function current_theme(): string
{
    $t = $_COOKIE[COOKIE_THEME] ?? '';
    return in_array($t, ['light', 'dark'], true) ? $t : '';
}

/** <img> for a profile photo, or the first letter of the name. */
function avatar_html(?string $photo, string $name, string $class = 'avatar'): string
{
    if ($photo && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $photo) && is_file(__DIR__ . '/../uploads/avatars/' . $photo)) {
        return '<span class="' . e($class) . '"><img src="' . e(BASE_URL . 'uploads/avatars/' . $photo) . '" alt=""></span>';
    }
    return '<span class="' . e($class) . '">' . e(strtoupper(mb_substr($name, 0, 1))) . '</span>';
}

function head_tags(string $title): void
{
    $base  = BASE_URL;
    $theme = current_theme();
    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= e($theme ?: 'light') ?>" data-theme-pref="<?= e($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <meta name="base-url" content="<?= e($base) ?>">
    <title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
    <?php if ($theme === ''): ?>
    <script>if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.setAttribute('data-bs-theme', 'dark');</script>
    <?php endif; ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232d6a4f'/%3E%3Cpath d='M16 7 4 13l12 6 12-6-12-6Zm-7 9v5c0 2 3.5 4 7 4s7-2 7-4v-5l-7 3.5L9 16Z' fill='%23fff'/%3E%3C/svg%3E">
    <!-- All libraries are bundled locally: the site works without internet -->
    <link rel="stylesheet" href="<?= e($base) ?>assets/vendor/inter/inter.css">
    <link rel="stylesheet" href="<?= e($base) ?>assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e($base) ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e($base) ?>assets/css/style.css?v=2">
</head>
    <?php
}

function theme_button(string $extra = ''): string
{
    return '<button type="button" class="icon-btn ' . e($extra) . '" data-theme-toggle aria-label="Switch light / dark mode" title="Switch light / dark mode">'
         . '<i class="bi bi-moon-stars theme-icon-light"></i><i class="bi bi-sun theme-icon-dark"></i></button>';
}

function page_start(string $title, ?string $role = null, string $active = ''): void
{
    global $pdo;
    $base = BASE_URL;
    head_tags($title);
    ?>
<body class="<?= $role === null ? 'page-plain' : 'page-app' ?>">
    <?php
    if ($role === null) {
        return;
    }
    $name    = $_SESSION['name'] ?? '';
    $photo   = $_SESSION['photo'] ?? null;
    $pending = 0;
    if ($role === 'Admin' && $pdo) {
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM application WHERE status = 'Pending'")->fetchColumn();
    }
    $sections = $role === 'Admin'
        ? [
            'Overview'   => ['dashboard' => ['admin/dashboard.php', 'grid-1x2', 'Dashboard']],
            'Management' => [
                'applications' => ['admin/applications.php', 'clipboard-check', 'Applications'],
                'scholarships' => ['admin/scholarships.php', 'award', 'Scholarships'],
                'students'     => ['admin/students.php', 'people', 'Students'],
                'departments'  => ['admin/departments.php', 'building', 'Departments'],
            ],
            'Finance'    => ['payments' => ['admin/payments.php', 'wallet2', 'Payments & Invoices']],
            'Account'    => ['profile' => ['admin/profile.php', 'person-gear', 'My Profile']],
          ]
        : [
            'Overview'   => ['dashboard' => ['student/dashboard.php', 'grid-1x2', 'Dashboard']],
            'Scholarships' => [
                'scholarships' => ['student/scholarships.php', 'award', 'Browse Scholarships'],
                'applications' => ['student/applications.php', 'file-earmark-text', 'My Applications'],
                'payments'     => ['student/payments.php', 'receipt', 'Payments & Invoices'],
            ],
            'Account'    => ['profile' => ['student/profile.php', 'person', 'My Profile']],
          ];
    ?>
<div class="app-shell">
    <aside class="app-sidebar" id="sidebar">
        <a class="brand" href="<?= e($base) ?>index.php">
            <span class="brand-mark"><i class="bi bi-mortarboard"></i></span>
            <span class="brand-text"><?= e(APP_NAME) ?><small><?= $role === 'Admin' ? 'Admin console' : 'Student portal' ?></small></span>
        </a>
        <nav class="sidebar-nav">
        <?php foreach ($sections as $label => $links): ?>
            <div class="sidebar-label"><?= e($label) ?></div>
            <?php foreach ($links as $key => [$href, $icon, $text]): ?>
                <a href="<?= e($base . $href) ?>" class="sidebar-link<?= $key === $active ? ' active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                    <i class="bi bi-<?= e($icon) ?>"></i><span><?= e($text) ?></span>
                    <?php if ($role === 'Admin' && $key === 'applications'): ?>
                        <span class="nav-count" data-pending-count <?= $pending ? '' : 'hidden' ?>><?= $pending ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </nav>
        <div class="sidebar-user">
            <?= avatar_html($photo, $name) ?>
            <div class="min-w-0">
                <div class="user-name text-truncate"><?= e($name) ?></div>
                <div class="user-role"><?= e($role === 'Admin' ? ($_SESSION['adminid'] ?? '') : ($_SESSION['studentid'] ?? '')) ?></div>
            </div>
            <a href="<?= e($base) ?>logout.php" class="sidebar-logout" title="Log out" aria-label="Log out"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-toggle></div>

    <div class="app-main">
        <header class="topbar">
            <button type="button" class="icon-btn topbar-menu" data-sidebar-toggle aria-label="Open menu"><i class="bi bi-list"></i></button>
            <?php if ($role === 'Admin'): ?>
            <form class="topbar-search" method="GET" action="<?= e($base) ?>admin/applications.php" role="search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" class="form-control" placeholder="Search applications, students, IDs…" aria-label="Search applications">
            </form>
            <?php else: ?>
            <div class="topbar-greeting"><?= e(date('l, d F Y')) ?></div>
            <?php endif; ?>
            <div class="topbar-right">
                <?= theme_button() ?>
                <div class="dropdown">
                    <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= avatar_html($photo, $name) ?>
                        <span class="user-meta">
                            <span class="user-name"><?= e($name) ?></span>
                            <span class="user-role"><?= e($role) ?></span>
                        </span>
                        <i class="bi bi-chevron-down small"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header"><?= e($_SESSION['username'] ?? '') ?></li>
                        <li><a class="dropdown-item" href="<?= e($base . ($role === 'Admin' ? 'admin' : 'student')) ?>/profile.php"><i class="bi bi-person"></i> My profile</a></li>
                        <li><a class="dropdown-item" href="<?= e($base . ($role === 'Admin' ? 'admin' : 'student')) ?>/payments.php"><i class="bi bi-receipt"></i> Payments &amp; invoices</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e($base) ?>logout.php"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
                    </ul>
                </div>
            </div>
        </header>
        <main class="app-content">
            <?= render_flash() ?>
    <?php
}

function page_end(bool $withLayout = false): void
{
    if ($withLayout) {
        echo '            <footer class="app-footer"><span>&copy; ' . date('Y') . ' ' . e(APP_NAME) . '</span><span>' . e(APP_TAGLINE) . "</span></footer>\n";
        echo "        </main>\n    </div>\n</div>\n";
    }
    if (!isset($_COOKIE[COOKIE_NOTICE])): ?>
<div class="cookie-notice no-print" id="cookieNotice" role="region" aria-label="Cookie notice">
    <p><i class="bi bi-shield-check"></i> We use cookies to keep you signed in and remember your preferences (such as dark mode).</p>
    <button type="button" class="btn btn-sm btn-primary" data-cookie-ok>Got it</button>
</div>
    <?php endif; ?>
<div class="toast-stack no-print" id="toastStack" aria-live="polite"></div>
<script src="<?= e(BASE_URL) ?>assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= e(BASE_URL) ?>assets/js/main.js?v=2"></script>
</body>
</html>
    <?php
}

/** Renders a list of validation errors (the container is also filled by AJAX). */
function error_list(array $errors): string
{
    $items = '';
    foreach ($errors as $err) {
        $items .= '<li>' . e($err) . '</li>';
    }
    return '<div class="alert alert-danger form-errors" role="alert" data-errors' . ($errors ? '' : ' hidden') . '>'
         . '<i class="bi bi-exclamation-circle"></i><div><strong>Please correct the following:</strong>'
         . '<ul>' . $items . '</ul></div></div>';
}

/** Split layout for login / register / installer. */
function auth_open(bool $wide = false): void
{ ?>
<div class="auth-wrapper">
    <aside class="auth-aside">
        <a class="brand" href="<?= e(BASE_URL) ?>index.php">
            <span class="brand-mark"><i class="bi bi-mortarboard"></i></span>
            <span class="brand-text"><?= e(APP_NAME) ?><small><?= e(APP_TAGLINE) ?></small></span>
        </a>
        <div>
            <h2>Scholarships, applications and payments in one place.</h2>
            <p>Apply for scholarships, pay the application fee securely and follow every decision as it happens.</p>
            <ul class="auth-points">
                <li><i class="bi bi-lightning-charge"></i> Apply in minutes and track your status live</li>
                <li><i class="bi bi-wallet2"></i> Pay with bKash, Nagad, Rocket or card</li>
                <li><i class="bi bi-receipt"></i> Download and print invoices anytime</li>
            </ul>
        </div>
        <small style="color:#86a594">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></small>
    </aside>
    <div class="auth-main">
        <div class="auth-theme"><?= theme_button() ?></div>
        <div class="auth-card<?= $wide ? ' auth-card-wide' : '' ?>">
            <div class="auth-mobile-brand"><span class="brand-mark"><i class="bi bi-mortarboard"></i></span><?= e(APP_NAME) ?></div>
<?php }

function auth_close(): void
{ ?>
        </div>
    </div>
</div>
<?php }

/** Printable document page (invoice, application copy, statement). */
function doc_open(string $title, string $backHref, string $backLabel): void
{
    head_tags($title); ?>
<body class="doc-page">
<div class="doc-toolbar no-print">
    <a href="<?= e($backHref) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> <?= e($backLabel) ?></a>
    <div class="ms-auto">
        <?= theme_button() ?>
        <button type="button" class="btn btn-outline-primary btn-sm" data-print title="Choose &quot;Save as PDF&quot; in the print dialog"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</button>
        <button type="button" class="btn btn-primary btn-sm" data-print><i class="bi bi-printer"></i> Print</button>
    </div>
</div>
<?php }

function doc_head(string $docTitle, array $lines): void
{ ?>
    <div class="doc-head">
        <div class="doc-brand">
            <span class="brand-mark"><i class="bi bi-mortarboard"></i></span>
            <div>
                <h1><?= e(APP_NAME) ?></h1>
                <p><?= e(MERCHANT_NAME) ?></p>
                <p><?= e(MERCHANT_ADDRESS) ?> &middot; <?= e(MERCHANT_EMAIL) ?></p>
            </div>
        </div>
        <div class="doc-title">
            <h2><?= e($docTitle) ?></h2>
            <?php foreach ($lines as $l): ?><p><?= $l ?></p><?php endforeach; ?>
        </div>
    </div>
<?php }

function doc_close(): void
{ ?>
<script src="<?= e(BASE_URL) ?>assets/js/main.js?v=2"></script>
</body>
</html>
<?php }
