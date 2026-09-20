<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payment.php';
requireAdmin();

$pdo->exec("UPDATE payment SET status = 'Expired', failreason = 'Checkout time ran out.'
    WHERE status = 'Initiated' AND createdat < NOW() - INTERVAL " . CHECKOUT_MINUTES . " MINUTE");

$statuses = ['All', 'Completed', 'Initiated', 'Failed', 'Cancelled', 'Expired'];
$status = in_array($_GET['status'] ?? 'Completed', $statuses, true) ? ($_GET['status'] ?? 'Completed') : 'Completed';
$method = isset(PAY_METHODS[$_GET['method'] ?? '']) ? $_GET['method'] : '';
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$search = trim((string) ($_GET['q'] ?? ''));
$filters = ['status' => $status, 'method' => $method, 'from' => $from, 'to' => $to, 'q' => $search];

/** Payments matching the filters (shared by the page, CSV export and statement). */
function find_payments(PDO $pdo, array $f): array
{
    $sql = "SELECT p.*, s.name AS student_name, sc.title
            FROM payment p
            JOIN student s ON s.studentid = p.studentid
            JOIN scholarship sc ON sc.scholarshipid = p.scholarshipid
            WHERE 1 = 1";
    $args = [];
    if ($f['status'] !== 'All') { $sql .= ' AND p.status = ?'; $args[] = $f['status']; }
    if ($f['method'] !== '')    { $sql .= ' AND p.method = ?'; $args[] = $f['method']; }
    if ($f['from'] !== '')      { $sql .= ' AND DATE(COALESCE(p.paidat, p.createdat)) >= ?'; $args[] = $f['from']; }
    if ($f['to'] !== '')        { $sql .= ' AND DATE(COALESCE(p.paidat, p.createdat)) <= ?'; $args[] = $f['to']; }
    if ($f['q'] !== '') {
        $sql .= ' AND (p.paymentid LIKE ? OR p.invoiceno LIKE ? OR p.trxid LIKE ? OR p.appid LIKE ? OR s.name LIKE ? OR s.studentid LIKE ? OR sc.title LIKE ?)';
        array_push($args, ...array_fill(0, 7, '%' . $f['q'] . '%'));
    }
    $sql .= ' ORDER BY COALESCE(p.paidat, p.createdat) DESC';
    $q = $pdo->prepare($sql);
    $q->execute($args);
    return $q->fetchAll();
}

$rows = find_payments($pdo, $filters);

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows the text correctly
    fputcsv($out, ['Payment ID', 'Invoice', 'Student ID', 'Student', 'Scholarship', 'Application', 'Amount (BDT)', 'Method', 'Account', 'Transaction ID', 'Status', 'Created', 'Paid at']);
    foreach ($rows as $r) {
        // prefix cells that could be read as spreadsheet formulas
        $cells = array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, [
            $r['paymentid'], $r['invoiceno'], $r['studentid'], $r['student_name'], $r['title'], $r['appid'],
            number_format((float) $r['amount'], 2, '.', ''), $r['method'], $r['account'], $r['trxid'], $r['status'], $r['createdat'], $r['paidat'],
        ]);
        fputcsv($out, $cells);
    }
    fclose($out);
    exit;
}

/* ---------- printable statement ---------- */
if (($_GET['view'] ?? '') === 'statement') {
    $sum = array_sum(array_map(fn($r) => $r['status'] === 'Completed' ? (float) $r['amount'] : 0, $rows));
    doc_open('Payment statement', 'payments.php?' . http_build_query($filters), 'Payments');
    ?>
<article class="doc">
    <?php doc_head('STATEMENT', [
        'Payments: <strong>' . e($status) . '</strong>' . ($method ? ' &middot; ' . e($method) : ''),
        'Period: ' . ($from ? fmt_date($from) : 'beginning') . ' to ' . ($to ? fmt_date($to) : 'today'),
        'Generated ' . date('d M Y, h:i A') . ' by ' . e($_SESSION['name']),
    ]); ?>
    <table class="doc-table compact" style="margin-top:1.4rem">
        <thead><tr><th>Invoice / Ref</th><th>Student</th><th>Scholarship</th><th>Method</th><th>Trx ID</th><th>Date</th><th>Status</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= e($r['invoiceno'] ?: $r['paymentid']) ?></td>
                <td><?= e($r['student_name']) ?><br><span style="color:#66756b"><?= e($r['studentid']) ?></span></td>
                <td><?= e($r['title']) ?></td>
                <td><?= e($r['method'] ?: '—') ?></td>
                <td class="nowrap"><?= e($r['trxid'] ?: '—') ?></td>
                <td class="nowrap"><?= date('d M Y', strtotime($r['paidat'] ?? $r['createdat'])) ?></td>
                <td><?= e($r['status']) ?></td>
                <td class="num"><?= money($r['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:#66756b">No payments match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="doc-totals">
        <div><span>Transactions</span><span><?= count($rows) ?></span></div>
        <div class="grand"><span>Total collected</span><span><?= money($sum) ?></span></div>
    </div>
    <div class="doc-foot"><span>Only completed payments are counted in the total.</span><span><?= e(APP_NAME) ?></span></div>
</article>
    <?php
    doc_close();
    exit;
}

/* ---------- summary numbers ---------- */
$totalAll  = (float) $pdo->query('SELECT fn_fee_collected(NULL)')->fetchColumn();
$today     = (float) $pdo->query("SELECT IFNULL(SUM(amount),0) FROM payment WHERE status='Completed' AND DATE(paidat) = CURDATE()")->fetchColumn();
$month     = (float) $pdo->query("SELECT IFNULL(SUM(amount),0) FROM payment WHERE status='Completed' AND DATE_FORMAT(paidat,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')")->fetchColumn();
$byMethod  = $pdo->query("SELECT method, COUNT(*) AS n, SUM(amount) AS total FROM payment WHERE status='Completed' GROUP BY method ORDER BY total DESC")->fetchAll();
$failed    = (int) $pdo->query("SELECT COUNT(*) FROM payment WHERE status IN ('Failed','Cancelled','Expired')")->fetchColumn();
$maxMethod = max(array_merge([1], array_map('floatval', array_column($byMethod, 'total'))));
$shownSum  = array_sum(array_map(fn($r) => $r['status'] === 'Completed' ? (float) $r['amount'] : 0, $rows));

page_start('Payments & Invoices', 'Admin', 'payments');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Payments &amp; Invoices</h1>
        <p class="page-subtitle">Application fees collected through bKash, Nagad, Rocket and card.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-primary" href="?<?= e(http_build_query($filters + ['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
        <a class="btn btn-primary" href="?<?= e(http_build_query($filters + ['view' => 'statement'])) ?>"><i class="bi bi-printer"></i> Print statement</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-icon tone-deep"><i class="bi bi-cash-stack"></i></span>
        <div><div class="stat-label">Total collected</div><div class="stat-value"><?= money($totalAll) ?></div></div></div>
    <div class="stat-card"><span class="stat-icon"><i class="bi bi-calendar-check"></i></span>
        <div><div class="stat-label">This month</div><div class="stat-value"><?= money($month) ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-sage"><i class="bi bi-sun"></i></span>
        <div><div class="stat-label">Today</div><div class="stat-value"><?= money($today) ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-amber"><i class="bi bi-exclamation-triangle"></i></span>
        <div><div class="stat-label">Unsuccessful checkouts</div><div class="stat-value"><?= $failed ?></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="content-card h-100">
            <form method="GET" class="row g-2 align-items-end" role="search">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="f-status">Status</label>
                    <select id="f-status" name="status" class="form-select">
                        <?php foreach ($statuses as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="f-method">Method</label>
                    <select id="f-method" name="method" class="form-select">
                        <option value="">All methods</option>
                        <?php foreach (PAY_METHODS as $m => $_): ?><option <?= $method === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="f-from">From</label><input type="date" id="f-from" name="from" class="form-control" value="<?= e($from) ?>"></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="f-to">To</label><input type="date" id="f-to" name="to" class="form-control" value="<?= e($to) ?>"></div>
                <div class="col-lg-9">
                    <div class="search-box w-100"><i class="bi bi-search"></i>
                        <input type="search" name="q" class="form-control" placeholder="Invoice, Trx ID, payment / application ID, student…" value="<?= e($search) ?>" aria-label="Search payments"></div>
                </div>
                <div class="col-lg-3 d-flex gap-2">
                    <button class="btn btn-primary flex-fill">Apply</button>
                    <a class="btn btn-outline-secondary" href="payments.php" title="Reset filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="content-card h-100">
            <h2 class="card-heading">Collected by method</h2>
            <div class="bar-chart">
                <?php foreach ($byMethod as $b): ?>
                <div class="bar-row">
                    <span><?= method_badge($b['method']) ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width: <?= round((float) $b['total'] / $maxMethod * 100) ?>%"></div></div>
                    <span class="bar-value"><?= money($b['total']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (!$byMethod): ?><div class="empty-state py-3">No completed payments yet.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="content-card">
    <h2 class="card-heading"><?= count($rows) ?> payment<?= count($rows) === 1 ? '' : 's' ?>
        <span class="text-muted fw-normal small">Completed total: <?= money($shownSum) ?></span></h2>
    <div class="table-responsive">
        <table class="table app-table">
            <thead><tr><th>Reference</th><th>Student</th><th>Scholarship</th><th>Amount</th><th>Method</th><th>Trx ID</th><th>Date</th><th>Status</th><th class="text-end">Invoice</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><span class="id-pill"><?= e($r['paymentid']) ?></span><?php if ($r['invoiceno']): ?><span class="cell-sub text-nowrap"><?= e($r['invoiceno']) ?></span><?php endif; ?></td>
                    <td><?= e($r['student_name']) ?><span class="cell-sub"><?= e($r['studentid']) ?></span></td>
                    <td><?= e($r['title']) ?><?php if ($r['appid']): ?><span class="cell-sub"><a href="review.php?id=<?= urlencode($r['appid']) ?>"><?= e($r['appid']) ?></a></span><?php endif; ?></td>
                    <td class="text-nowrap fw-semibold"><?= money($r['amount']) ?></td>
                    <td><?= method_badge($r['method']) ?><?php if ($r['account']): ?><span class="cell-sub text-nowrap"><?= e($r['account']) ?></span><?php endif; ?></td>
                    <td><?= $r['trxid'] ? '<code>' . e($r['trxid']) . '</code>' : '<span class="text-muted">&mdash;</span>' ?></td>
                    <td class="text-nowrap"><?= date('d M Y', strtotime($r['paidat'] ?? $r['createdat'])) ?><span class="cell-sub"><?= date('h:i A', strtotime($r['paidat'] ?? $r['createdat'])) ?></span></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($r['status'] === 'Completed'): ?>
                            <a class="btn btn-sm btn-soft" href="../invoice.php?id=<?= urlencode($r['paymentid']) ?>" title="View / print invoice"><i class="bi bi-receipt"></i> Invoice</a>
                        <?php else: ?><span class="text-muted small"><?= e($r['failreason'] ?: '—') ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-wallet2"></i>No payments match these filters.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php page_end(true); ?>
