<?php
/** Hosted checkout page (one page, steps are swapped by AJAX). */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/pay_ui.php';
requireStudent();

$p = load_payment($pdo, (string) ($_GET['id'] ?? ''));
if (!$p || $p['studentid'] !== $_SESSION['studentid']) {
    finish('danger', 'Payment not found.', 'student/payments.php');
}
$p = expire_if_needed($pdo, $p);

$st = $_SESSION['pay'][$p['paymentid']] ?? ['step' => 'method'];
$error = $st['error'] ?? '';
unset($_SESSION['pay'][$p['paymentid']]['error']);

head_tags('Checkout ' . $p['paymentid']);
?>
<body class="page-plain">
<div class="pay-page">
    <?php if (PAYMENT_MODE === 'test'): ?>
        <div class="test-banner"><i class="bi bi-cone-striped"></i> TEST MODE &middot; No real money is charged</div>
    <?php endif; ?>
    <div class="pay-card" id="payCard" data-seconds-left="<?= $p['status'] === 'Initiated' ? max(0, (int) $p['seconds_left']) : 0 ?>">
        <div class="pay-head">
            <span class="brand-mark"><i class="bi bi-mortarboard"></i></span>
            <div class="pay-merchant"><?= e(MERCHANT_NAME) ?><small>Secure checkout &middot; <?= e($p['paymentid']) ?></small></div>
            <?php if ($p['status'] === 'Initiated'): ?>
                <div class="pay-timer" data-pay-timer title="Time left to finish this payment"><i class="bi bi-clock"></i> <span>--:--</span></div>
            <?php endif; ?>
        </div>
        <div id="payStage" data-step="<?= e($p['status'] === 'Initiated' ? ($st['step'] ?? 'method') : 'result') ?>"><?= render_stage($p, $st, $error) ?></div>
        <div class="pay-foot">
            <span class="pay-secure"><i class="bi bi-shield-lock"></i> Encrypted &amp; secure</span>
            <?php if ($p['status'] === 'Initiated'): ?>
            <form method="POST" action="action.php" data-pay data-confirm="Cancel this payment? Your application will not be submitted.">
                <?= csrfField() ?>
                <input type="hidden" name="pid" value="<?= e($p['paymentid']) ?>">
                <button type="submit" name="action" value="cancel" class="link-btn text-muted" formnovalidate>Cancel payment</button>
            </form>
            <?php else: ?>
            <a href="<?= e(BASE_URL) ?>student/payments.php">My payments</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-3"><?= theme_button() ?></div>
</div>
<div class="sms-popup" id="smsPopup" role="status" aria-live="polite">
    <span class="ic"><i class="bi bi-chat-dots"></i></span>
    <div><div class="from"><span data-sms-from></span><span>now</span></div><div class="msg" data-sms-text></div></div>
</div>
<div class="toast-stack" id="toastStack" aria-live="polite"></div>
<script src="<?= e(BASE_URL) ?>assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= e(BASE_URL) ?>assets/js/main.js?v=2"></script>
<script src="<?= e(BASE_URL) ?>assets/js/pay.js?v=1"></script>
</body>
</html>
