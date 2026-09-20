<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireStudent();

$sid    = $_SESSION['studentid'];
$search = trim((string) ($_GET['q'] ?? ''));
$type   = trim((string) ($_GET['type'] ?? ''));
$show   = ($_GET['show'] ?? 'open') === 'all' ? 'all' : 'open';

$sql = "
    SELECT sc.*, ad.name AS creator,
        (SELECT COUNT(*) FROM application a WHERE a.scholarshipid = sc.scholarshipid AND a.status = 'Approved') AS approved,
        (SELECT a.status FROM application a WHERE a.scholarshipid = sc.scholarshipid AND a.studentid = ?) AS my_status,
        (SELECT a.appid  FROM application a WHERE a.scholarshipid = sc.scholarshipid AND a.studentid = ?) AS my_appid
    FROM scholarship sc
    JOIN admin ad ON ad.adminid = sc.createdby
    WHERE 1 = 1";
$params = [$sid, $sid];
if ($show === 'open') {
    $sql .= ' AND sc.deadline >= CURDATE()';
}
if ($type !== '') {
    $sql .= ' AND sc.type = ?';
    $params[] = $type;
}
if ($search !== '') {
    $sql .= ' AND sc.title LIKE ?';
    $params[] = '%' . $search . '%';
}
$sql .= ' ORDER BY (sc.deadline >= CURDATE()) DESC, sc.deadline ASC';
$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

$types = $pdo->query('SELECT DISTINCT type FROM scholarship WHERE type IS NOT NULL ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);

/** Tabs + scholarship cards. Returned alone for AJAX (live filtering). */
function render_results(array $list, string $show, string $type, string $search): void
{ ?>
    <div class="filter-tabs mb-3">
        <a class="filter-tab <?= $show === 'open' ? 'active' : '' ?>" data-live-set="show=open" href="?<?= e(http_build_query(['show' => 'open', 'type' => $type, 'q' => $search])) ?>">Open now</a>
        <a class="filter-tab <?= $show === 'all' ? 'active' : '' ?>" data-live-set="show=all" href="?<?= e(http_build_query(['show' => 'all', 'type' => $type, 'q' => $search])) ?>">All (including closed)</a>
    </div>

    <?php if (!$list): ?>
        <div class="content-card"><div class="empty-state"><i class="bi bi-award"></i>
            No scholarships found<?= $show === 'open' ? ' that are open right now. <a href="?show=all" data-live-set="show=all">Show closed ones too</a>' : '.' ?>
        </div></div>
    <?php endif; ?>

    <div class="scholarship-grid">
    <?php foreach ($list as $s):
        $open  = $s['deadline'] >= date('Y-m-d');
        $full  = (int) $s['approved'] >= (int) $s['totalslots'];
        $pct   = $s['totalslots'] > 0 ? min(100, round($s['approved'] / $s['totalslots'] * 100)) : 100;
        $daysLeft = (int) floor((strtotime($s['deadline']) - strtotime(date('Y-m-d'))) / 86400); ?>
        <div class="scholarship-card">
            <div class="sch-top">
                <span class="type-badge"><?= e($s['type'] ?: 'General') ?></span>
                <?php if ($s['my_status']): ?>
                    <?= status_badge($s['my_status']) ?>
                <?php elseif (!$open): ?>
                    <span class="status-badge status-closed">Closed</span>
                <?php elseif ($full): ?>
                    <span class="status-badge status-full">Slots full</span>
                <?php else: ?>
                    <span class="status-badge status-open"><?= $daysLeft === 0 ? 'Closes today' : $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left' ?></span>
                <?php endif; ?>
            </div>
            <h4><?= e($s['title']) ?></h4>
            <div class="amount"><?= money($s['amount']) ?></div>
            <?php if ($s['description']): ?><div class="sch-desc"><?= e($s['description']) ?></div><?php endif; ?>
            <div class="sch-meta"><i class="bi bi-calendar-event"></i> Deadline: <?= fmt_date($s['deadline']) ?></div>
            <div class="sch-meta"><i class="bi bi-wallet2"></i> Application fee: <?= (float) $s['applicationfee'] > 0 ? money($s['applicationfee']) : 'Free' ?></div>
            <div class="sch-meta"><i class="bi bi-person"></i> Offered by <?= e($s['creator']) ?> &middot; <?= e($s['scholarshipid']) ?></div>
            <div class="sch-meta mt-2"><i class="bi bi-people"></i> <span class="text-nowrap"><?= (int) $s['approved'] ?> of <?= (int) $s['totalslots'] ?> slots filled</span></div>
            <div class="slot-bar"><span style="width: <?= $pct ?>%"></span></div>
            <div class="mt-auto">
                <?php if ($s['my_status']): ?>
                    <a href="applications.php" class="btn btn-outline-primary w-100">View my application (<?= e($s['my_appid']) ?>)</a>
                <?php elseif (!$open): ?>
                    <button class="btn btn-outline-secondary w-100" disabled>Deadline passed</button>
                <?php elseif ($full): ?>
                    <button class="btn btn-outline-secondary w-100" disabled>No slots left</button>
                <?php else: ?>
                    <a href="apply.php?id=<?= urlencode($s['scholarshipid']) ?>" class="btn btn-primary w-100">Apply now</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php }

if (is_ajax() && isset($_GET['partial'])) {
    render_results($list, $show, $type, $search);
    exit;
}

page_start('Scholarships', 'Student', 'scholarships');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Scholarships</h1>
        <p class="page-subtitle">Browse available scholarships and submit your application.</p>
    </div>
    <form method="GET" class="d-flex gap-2 flex-wrap filter-bar mb-0" data-live="#results" role="search">
        <input type="hidden" name="show" value="<?= e($show) ?>">
        <select name="type" class="form-select" aria-label="Filter by type">
            <option value="">All types</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="search" name="q" class="form-control" placeholder="Search by title…" value="<?= e($search) ?>" maxlength="100" aria-label="Search scholarships">
        </div>
        <noscript><button class="btn btn-primary">Filter</button></noscript>
    </form>
</div>

<div id="results" data-live-target>
    <?php render_results($list, $show, $type, $search); ?>
</div>
<?php page_end(true); ?>
