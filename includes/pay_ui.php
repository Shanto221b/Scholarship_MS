<?php
/**
 * HTML for each checkout step. Used by pay/checkout.php (full page) and
 * pay/action.php (AJAX: the step is swapped in without a page reload).
 */

function pay_hidden(array $p, string $action): string
{
    return csrfField()
        . '<input type="hidden" name="pid" value="' . e($p['paymentid']) . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">';
}

function code_boxes(int $n, string $cls = ''): string
{
    $html = '<div class="code-inputs ' . e($cls) . '" data-code-inputs>';
    for ($i = 0; $i < $n; $i++) {
        $html .= '<input type="' . ($cls === 'pin' ? 'password' : 'text') . '" name="d[]" inputmode="numeric" pattern="[0-9]" maxlength="1" required autocomplete="one-time-code" aria-label="Digit ' . ($i + 1) . '">';
    }
    return $html . '</div>';
}

function pay_error_html(string $error): string
{
    return '<div class="pay-error" data-pay-error role="alert">' . e($error) . '</div>';
}

/** Coloured header used on every wallet / card step. */
function wallet_head(array $p, string $method, int $step, int $steps, string $title): string
{
    $m = PAY_METHODS[$method];
    $bar = '';
    for ($i = 1; $i <= $steps; $i++) {
        $bar .= '<span class="' . ($i <= $step ? 'done' : '') . '"></span>';
    }
    return '<div class="wallet-head"><span class="method-logo">' . e($m['logo']) . '</span>'
         . '<div class="t">' . e($title) . '<small>' . e(APP_NAME) . ' &middot; ' . e($p['paymentid']) . '</small></div>'
         . '<div class="amt">' . money($p['amount']) . '<small>Application fee</small></div></div>'
         . '<div class="wallet-steps" aria-hidden="true">' . $bar . '</div>';
}

function render_stage(array $p, array $st, string $error = ''): string
{
    if ($p['status'] !== 'Initiated') {
        return render_result($p);
    }
    $step   = $st['step'] ?? 'method';
    $method = $st['method'] ?? null;
    $test   = PAYMENT_MODE === 'test';
    ob_start();

    if ($step === 'method' || !$method): ?>
        <div class="pay-summary">
            <span class="k">Payment for</span><span class="v"><?= e($p['title']) ?></span>
            <span class="k">Applicant</span><span class="v"><?= e($p['student_name']) ?> (<?= e($p['studentid']) ?>)</span>
            <span class="k">Reference</span><span class="v"><?= e($p['paymentid']) ?></span>
            <span class="k align-self-center">Amount to pay</span><span class="v total"><?= money($p['amount']) ?></span>
        </div>
        <form class="pay-body" method="POST" action="action.php" data-pay>
            <?= pay_hidden($p, 'method') ?>
            <div class="pay-step-title">Choose a payment method</div>
            <div class="method-grid" role="radiogroup" aria-label="Payment method">
                <?php foreach (PAY_METHODS as $name => $m): ?>
                <label class="method-tile tile-<?= e($m['key']) ?><?= $method === $name ? ' selected' : '' ?>">
                    <input type="radio" name="method" value="<?= e($name) ?>" class="visually-hidden" required <?= $method === $name ? 'checked' : '' ?>>
                    <i class="bi bi-check-circle-fill tick"></i>
                    <span class="method-logo"><?= e($m['logo']) ?></span>
                    <span><span class="method-name"><?= e($name) ?></span><br><span class="method-sub"><?= e($m['sub']) ?></span></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?= pay_error_html($error) ?>
            <button type="submit" class="btn btn-primary btn-lg w-100 mt-2" data-busy="Please wait…">Continue</button>
        </form>
    <?php
    elseif ($method === 'Card'):
        $k = PAY_METHODS[$method]['key']; ?>
        <div class="wallet wallet-<?= e($k) ?>">
        <?php if ($step === 'card'): ?>
            <?= wallet_head($p, $method, 1, 2, 'Pay by card') ?>
            <form class="pay-body" method="POST" action="action.php" data-pay autocomplete="off">
                <?= pay_hidden($p, 'card') ?>
                <div class="mb-3">
                    <label class="form-label d-flex justify-content-between" for="cardno">Card number <span class="card-brand" data-card-brand></span></label>
                    <input type="text" id="cardno" name="cardno" class="form-control form-control-lg" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456" maxlength="23" required data-card-number>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="cardname">Name on card</label>
                    <input type="text" id="cardname" name="cardname" class="form-control" autocomplete="cc-name" maxlength="60" required>
                </div>
                <div class="row g-3 mb-1">
                    <div class="col-6">
                        <label class="form-label" for="expiry">Expiry (MM/YY)</label>
                        <input type="text" id="expiry" name="expiry" class="form-control" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/YY" maxlength="5" required data-card-expiry>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="cvv">CVV</label>
                        <input type="password" id="cvv" name="cvv" class="form-control" inputmode="numeric" autocomplete="cc-csc" placeholder="•••" maxlength="4" required>
                    </div>
                </div>
                <?= pay_error_html($error) ?>
                <button type="submit" class="btn btn-wallet btn-lg w-100 mt-2" data-busy="Checking card…">Pay <?= strip_tags(money($p['amount'])) ?></button>
                <?php if ($test): ?>
                <p class="pay-note">Test cards: <code>4242 4242 4242 4242</code> (approved) &middot; <code>4000 0000 0000 0002</code> (declined). Any future expiry, any CVV.</p>
                <?php endif; ?>
            </form>
        <?php else: /* card_otp */ ?>
            <?= wallet_head($p, $method, 2, 2, '3-D Secure verification') ?>
            <form class="pay-body" method="POST" action="action.php" data-pay>
                <?= pay_hidden($p, 'otp') ?>
                <p class="wallet-label">Your bank sent a 6-digit code to the phone number linked to <strong><?= e($st['account']) ?></strong>.</p>
                <?= code_boxes(6) ?>
                <?= pay_error_html($error) ?>
                <button type="submit" class="btn btn-wallet btn-lg w-100 mt-3" data-busy="Verifying…">Verify &amp; pay</button>
                <p class="pay-note">Didn't get it? <button type="submit" class="link-btn" name="action" value="resend" formnovalidate data-resend="<?= max(0, (int) ($st['resend_at'] ?? 0) - time()) ?>">Resend code</button>
                <?php if ($test): ?><br>Test mode code: <code data-test-code><?= e($st['test_code'] ?? '') ?></code><?php endif; ?></p>
            </form>
        <?php endif; ?>
        </div>
    <?php
    else:
        $m = PAY_METHODS[$method]; ?>
        <div class="wallet wallet-<?= e($m['key']) ?>">
        <?php if ($step === 'wallet'): ?>
            <?= wallet_head($p, $method, 1, 3, 'Pay with ' . $method) ?>
            <form class="pay-body" method="POST" action="action.php" data-pay>
                <?= pay_hidden($p, 'wallet') ?>
                <p class="wallet-label">Enter your <?= e($method) ?> account number</p>
                <div class="phone-input">
                    <span class="cc">+88</span>
                    <input type="tel" name="wallet" inputmode="numeric" maxlength="11" pattern="01[3-9][0-9]{8}" placeholder="01XXXXXXXXX" required autocomplete="tel-national" value="<?= e($st['wallet'] ?? '') ?>" aria-label="<?= e($method) ?> account number">
                </div>
                <div class="form-check mt-3 small">
                    <input class="form-check-input" type="checkbox" id="agree" name="agree" value="1" required>
                    <label class="form-check-label" for="agree">I agree to the payment terms and allow <?= e(APP_NAME) ?> to charge this account.</label>
                </div>
                <?= pay_error_html($error) ?>
                <button type="submit" class="btn btn-wallet btn-lg w-100 mt-2" data-busy="Sending code…">Confirm</button>
                <?php if ($test): ?>
                <p class="pay-note">Test mode: any valid number works. <code><?= e(TEST_LOW_BALANCE_WALLET) ?></code> simulates insufficient balance.</p>
                <?php endif; ?>
            </form>
        <?php elseif ($step === 'otp'): ?>
            <?= wallet_head($p, $method, 2, 3, 'Verification code') ?>
            <form class="pay-body" method="POST" action="action.php" data-pay>
                <?= pay_hidden($p, 'otp') ?>
                <p class="wallet-label">Enter the 6-digit code sent to <strong><?= e(mask_wallet($st['wallet'])) ?></strong></p>
                <?= code_boxes(6) ?>
                <?= pay_error_html($error) ?>
                <button type="submit" class="btn btn-wallet btn-lg w-100 mt-3" data-busy="Verifying…">Confirm</button>
                <p class="pay-note">Didn't get the code? <button type="submit" class="link-btn" name="action" value="resend" formnovalidate data-resend="<?= max(0, (int) ($st['resend_at'] ?? 0) - time()) ?>">Resend code</button>
                <?php if ($test): ?><br>Test mode code: <code data-test-code><?= e($st['test_code'] ?? '') ?></code><?php endif; ?></p>
            </form>
        <?php else: /* pin */ ?>
            <?= wallet_head($p, $method, 3, 3, 'Enter PIN') ?>
            <form class="pay-body" method="POST" action="action.php" data-pay>
                <?= pay_hidden($p, 'pin') ?>
                <p class="wallet-label">Enter your <?= e($method) ?> PIN for <strong><?= e(mask_wallet($st['wallet'])) ?></strong></p>
                <?= code_boxes($m['pin'], 'pin') ?>
                <?= pay_error_html($error) ?>
                <button type="submit" class="btn btn-wallet btn-lg w-100 mt-3" data-busy="Processing payment…">Confirm payment</button>
                <p class="pay-note"><i class="bi bi-lock"></i> Your PIN is never stored.
                <?php if ($test): ?><br>Test mode PIN: <code><?= e(TEST_PINS[$method]) ?></code><?php endif; ?></p>
            </form>
        <?php endif; ?>
        </div>
    <?php
    endif;
    return (string) ob_get_clean();
}

function render_result(array $p): string
{
    ob_start();
    if ($p['status'] === 'Completed'): ?>
        <div class="pay-result">
            <span class="result-icon"><i class="bi bi-check-lg"></i></span>
            <h2 class="h5 fw-semibold mb-1">Payment successful</h2>
            <p class="text-muted mb-0">Your application <span class="id-pill"><?= e($p['appid']) ?></span> has been submitted for review.</p>
            <div class="receipt-lines">
                <span class="k">Amount</span><span class="v"><?= money($p['amount']) ?></span>
                <span class="k">Method</span><span class="v"><?= e($p['method']) ?> &middot; <?= e($p['account']) ?></span>
                <span class="k">Transaction ID</span><span class="v" data-trxid><?= e($p['trxid']) ?></span>
                <span class="k">Invoice</span><span class="v"><?= e($p['invoiceno']) ?></span>
                <span class="k">Paid at</span><span class="v"><?= date('d M Y, h:i A', strtotime($p['paidat'])) ?></span>
            </div>
            <div class="d-grid gap-2">
                <a class="btn btn-primary" href="<?= e(BASE_URL) ?>invoice.php?id=<?= urlencode($p['paymentid']) ?>"><i class="bi bi-receipt"></i> View &amp; print invoice</a>
                <a class="btn btn-outline-primary" href="<?= e(BASE_URL) ?>student/applications.php">Go to my applications</a>
            </div>
        </div>
    <?php else:
        $titles = ['Failed' => 'Payment failed', 'Cancelled' => 'Payment cancelled', 'Expired' => 'Checkout expired']; ?>
        <div class="pay-result">
            <span class="result-icon fail"><i class="bi bi-<?= $p['status'] === 'Failed' ? 'x-lg' : 'clock-history' ?>"></i></span>
            <h2 class="h5 fw-semibold mb-1"><?= e($titles[$p['status']] ?? 'Payment not completed') ?></h2>
            <p class="text-muted"><?= e($p['failreason'] ?: 'No money was taken from your account.') ?></p>
            <p class="small text-muted">Reference <?= e($p['paymentid']) ?>. No application was submitted.</p>
            <div class="d-grid gap-2">
                <a class="btn btn-primary" href="<?= e(BASE_URL) ?>student/apply.php?id=<?= urlencode($p['scholarshipid']) ?>">Try again</a>
                <a class="btn btn-outline-primary" href="<?= e(BASE_URL) ?>student/scholarships.php">Back to scholarships</a>
            </div>
        </div>
    <?php endif;
    return (string) ob_get_clean();
}
