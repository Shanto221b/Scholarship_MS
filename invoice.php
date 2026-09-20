<?php
/** Invoice for a completed application-fee payment (student: own only, admin: any). */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/payment.php';

if (!isLoggedIn()) {
    deny('Please log in to continue.', 'login.php');
}
$isAdmin = $_SESSION['role'] === 'Admin';
$back    = $isAdmin ? 'admin/payments.php' : 'student/payments.php';

$p = load_payment($pdo, (string) ($_GET['id'] ?? ''));
if (!$p || (!$isAdmin && $p['studentid'] !== ($_SESSION['studentid'] ?? ''))) {
    finish('danger', 'Invoice not found.', $back);
}
if ($p['status'] !== 'Completed') {
    finish('warning', "Payment {$p['paymentid']} is {$p['status']}, so it has no invoice.", $back);
}

$s = $pdo->prepare('SELECT s.*, d.deptname, d.facultyname FROM student s JOIN department d ON d.deptid = s.deptid WHERE s.studentid = ?');
$s->execute([$p['studentid']]);
$stu = $s->fetch();

doc_open('Invoice ' . $p['invoiceno'], BASE_URL . $back, $isAdmin ? 'Payments' : 'My payments');
?>
<article class="doc" aria-label="Invoice">
    <div class="doc-stamp">PAID</div>
    <?php doc_head('INVOICE', [
        '<strong>' . e($p['invoiceno']) . '</strong>',
        'Issued ' . date('d M Y', strtotime($p['paidat'])),
    ]); ?>

    <div class="doc-parties">
        <div>
            <h3>Billed to</h3>
            <p><strong><?= e($stu['name']) ?></strong> (<?= e($stu['studentid']) ?>)<br>
               <?= e($stu['deptname']) ?>, <?= e($stu['facultyname']) ?><br>
               <?= e($stu['email']) ?><?= $stu['phone'] ? ' &middot; ' . e($stu['phone']) : '' ?>
               <?= $stu['address'] ? '<br>' . e($stu['address']) : '' ?></p>
        </div>
        <div>
            <h3>Details</h3>
            <p>Payment reference: <strong><?= e($p['paymentid']) ?></strong><br>
               Application: <strong><?= e($p['appid']) ?></strong><br>
               Scholarship: <?= e($p['scholarshipid']) ?></p>
        </div>
    </div>

    <table class="doc-table">
        <thead><tr><th>#</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
        <tbody>
            <tr>
                <td>1</td>
                <td><strong>Application fee</strong><br><span style="color:#66756b"><?= e($p['title']) ?> (<?= e($p['type'] ?: 'General') ?>)</span></td>
                <td class="num">1</td>
                <td class="num"><?= money($p['amount']) ?></td>
                <td class="num"><?= money($p['amount']) ?></td>
            </tr>
        </tbody>
    </table>
    <div class="doc-totals">
        <div><span>Subtotal</span><span><?= money($p['amount']) ?></span></div>
        <div><span>VAT / tax</span><span><?= money(0) ?></span></div>
        <div class="grand"><span>Total paid</span><span><?= money($p['amount']) ?></span></div>
    </div>

    <div class="doc-pay">
        <div><div class="k">Payment method</div><div class="v"><?= e($p['method']) ?></div></div>
        <div><div class="k">Account</div><div class="v"><?= e($p['account']) ?></div></div>
        <div><div class="k">Transaction ID</div><div class="v"><?= e($p['trxid']) ?></div></div>
        <div><div class="k">Paid on</div><div class="v"><?= date('d M Y, h:i A', strtotime($p['paidat'])) ?></div></div>
    </div>

    <div class="doc-foot">
        <span>Application fees are non-refundable. This is a computer-generated invoice and needs no signature.</span>
        <span><?= PAYMENT_MODE === 'test' ? 'Test-mode transaction' : '' ?></span>
    </div>
</article>
<?php doc_close(); ?>
