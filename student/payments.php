<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payment.php';
requireStudent();

$sid = $_SESSION['studentid'];
// close checkouts whose time ran out, so the list is accurate
$pdo->prepare("UPDATE payment SET status = 'Expired', failreason = 'Checkout time ran out.'
    WHERE studentid = ? AND status = 'Initiated' AND createdat < NOW() - INTERVAL " . CHECKOUT_MINUTES . " MINUTE")->execute([$sid]);

$q = $pdo->prepare('
    SELECT p.*, sc.title FROM payment p JOIN scholarship sc ON sc.scholarshipid = p.scholarshipid
    WHERE p.studentid = ? ORDER BY p.createdat DESC');
$q->execute([$sid]);
$rows = $q->fetchAll();

$paid = array_filter($rows, fn($r) => $r['status'] === 'Completed');
$a = $pdo->prepare('SELECT scholarshipid FROM application WHERE studentid = ?');
$a->execute([$sid]);
$applied = array_flip($a->fetchAll(PDO::FETCH_COLUMN));
$total = array_sum(array_column($paid, 'amount'));

page_start('Payments & Invoices', 'Student', 'payments');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Payments &amp; Invoices</h1>
        <p class="page-subtitle">Every application-fee payment you made, with printable invoices.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-outline-primary" data-print><i class="bi bi-printer"></i> Print list</button>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
        <div><div class="stat-label">Total paid</div><div class="stat-value"><?= money($total) ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-sage"><i class="bi bi-receipt"></i></span>
        <div><div class="stat-label">Invoices</div><div class="stat-value"><?= count($paid) ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-amber"><i class="bi bi-x-circle"></i></span>
        <div><div class="stat-label">Unsuccessful attempts</div><div class="stat-value"><?= count($rows) - count($paid) - count(array_filter($rows, fn($r) => $r['status'] === 'Initiated')) ?></div></div></div>
</div>

<div class="content-card">
    <div class="table-responsive">
        <table class="table app-table">
            <thead><tr><th>Reference</th><th>Scholarship</th><th>Amount</th><th>Method</th><th>Transaction</th><th>Date</th><th>Status</th><th class="text-end no-print">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><span class="id-pill"><?= e($r['paymentid']) ?></span><?php if ($r['invoiceno']): ?><span class="cell-sub text-nowrap"><?= e($r['invoiceno']) ?></span><?php endif; ?></td>
                    <td><?= e($r['title']) ?><?php if ($r['appid']): ?><span class="cell-sub">Application <?= e($r['appid']) ?></span><?php endif; ?></td>
                    <td class="text-nowrap fw-semibold"><?= money($r['amount']) ?></td>
                    <td><?= method_badge($r['method']) ?><?php if ($r['account']): ?><span class="cell-sub text-nowrap"><?= e($r['account']) ?></span><?php endif; ?></td>
                    <td><?= $r['trxid'] ? '<code>' . e($r['trxid']) . '</code>' : '<span class="text-muted">&mdash;</span>' ?></td>
                    <td class="text-nowrap"><?= date('d M Y', strtotime($r['paidat'] ?? $r['createdat'])) ?><span class="cell-sub"><?= date('h:i A', strtotime($r['paidat'] ?? $r['createdat'])) ?></span></td>
                    <td><?= status_badge($r['status']) ?><?php if ($r['failreason'] && $r['status'] !== 'Completed'): ?><span class="cell-sub"><?= e($r['failreason']) ?></span><?php endif; ?></td>
                    <td class="text-end text-nowrap no-print">
                        <?php if ($r['status'] === 'Completed'): ?>
                            <a class="btn btn-sm btn-soft" href="../invoice.php?id=<?= urlencode($r['paymentid']) ?>"><i class="bi bi-receipt"></i> Invoice</a>
                        <?php elseif ($r['status'] === 'Initiated'): ?>
                            <a class="btn btn-sm btn-primary" href="../pay/checkout.php?id=<?= urlencode($r['paymentid']) ?>">Continue payment</a>
                        <?php elseif (!isset($applied[$r['scholarshipid']])): ?>
                            <a class="btn btn-sm btn-outline-primary" href="apply.php?id=<?= urlencode($r['scholarshipid']) ?>">Try again</a>
                        <?php else: ?>
                            <span class="text-muted small">Applied later</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-wallet2"></i>No payments yet. Fees are paid when you apply for a scholarship.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php page_end(true); ?>
