<?php
/**
 * Checkout steps. Every request is checked against the step the checkout is
 * really in, so a step can never be skipped (e.g. PIN before the code).
 * AJAX: returns JSON with the next step's HTML. Without JS: redirects back.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/pay_ui.php';
requireStudent();

$pid    = (string) ($_POST['pid'] ?? '');
$action = (string) ($_POST['action'] ?? '');
$back   = 'pay/checkout.php?id=' . urlencode($pid);

/** Sends the current step (or a redirect) back to the browser. */
function respond(PDO $pdo, string $pid, string $error = '', array $extra = []): void
{
    global $back;
    $p  = load_payment($pdo, $pid);
    $st = $_SESSION['pay'][$pid] ?? ['step' => 'method'];
    if (is_ajax()) {
        json_response(['ok' => $error === '', 'error' => $error, 'status' => $p['status'],
            'step' => $p['status'] === 'Initiated' ? ($st['step'] ?? 'method') : 'result',
            'stage' => render_stage($p, $st, $error)] + $extra);
    }
    if ($error !== '') {
        $_SESSION['pay'][$pid]['error'] = $error;
    }
    redirect($back);
}

function new_code(string $pid): string
{
    $code = (string) random_int(100000, 999999);
    $_SESSION['pay'][$pid]['otp_hash']  = hash('sha256', $pid . $code);
    $_SESSION['pay'][$pid]['otp_exp']   = time() + OTP_SECONDS;
    $_SESSION['pay'][$pid]['resend_at'] = time() + RESEND_SECONDS;
    $_SESSION['pay'][$pid]['tries']     = 0;
    $_SESSION['pay'][$pid]['test_code'] = PAYMENT_MODE === 'test' ? $code : '';
    return $code;
}

function sms_payload(string $method, string $code): array
{
    return ['sms' => [
        'from' => $method === 'Card' ? 'Your Bank' : $method,
        'text' => "Your {$method} verification code is <b>{$code}</b>. It is valid for 3 minutes. Never share it with anyone.",
    ]];
}

if (!isPost() || !verifyCsrfToken()) {
    finish('danger', 'Your session expired. Please reload the page and try again.', $back);
}
$p = load_payment($pdo, $pid);
if (!$p || $p['studentid'] !== $_SESSION['studentid']) {
    finish('danger', 'Payment not found.', 'student/payments.php');
}
$p = expire_if_needed($pdo, $p);
if ($p['status'] !== 'Initiated') {
    respond($pdo, $pid);
}

$st     = $_SESSION['pay'][$pid] ?? ['step' => 'method'];
$step   = $st['step'] ?? 'method';
$method = $st['method'] ?? null;

switch ($action) {
    case 'cancel':
        $pdo->prepare("UPDATE payment SET status = 'Cancelled', failreason = 'Cancelled by the student.' WHERE paymentid = ? AND status = 'Initiated'")->execute([$pid]);
        unset($_SESSION['pay'][$pid]);
        finish('warning', 'Payment cancelled. Your application was not submitted.', 'student/apply.php?id=' . urlencode($p['scholarshipid']));

    case 'back':
        $_SESSION['pay'][$pid] = ['step' => 'method', 'method' => $method];
        respond($pdo, $pid);

    case 'method':
        $chosen = (string) ($_POST['method'] ?? '');
        if (!isset(PAY_METHODS[$chosen])) {
            respond($pdo, $pid, 'Please choose a payment method.');
        }
        $_SESSION['pay'][$pid] = ['step' => $chosen === 'Card' ? 'card' : 'wallet', 'method' => $chosen];
        respond($pdo, $pid);

    case 'wallet':
        if ($step !== 'wallet') {
            respond($pdo, $pid);
        }
        $wallet = preg_replace('/\D/', '', (string) ($_POST['wallet'] ?? ''));
        if (str_starts_with($wallet, '88')) {
            $wallet = substr($wallet, 2);
        }
        $_SESSION['pay'][$pid]['wallet'] = $wallet;
        if (!valid_bd_mobile($wallet)) {
            respond($pdo, $pid, 'Enter a valid 11-digit mobile number (01XXXXXXXXX).');
        }
        if (($_POST['agree'] ?? '') !== '1') {
            respond($pdo, $pid, 'Please accept the payment terms to continue.');
        }
        $code = new_code($pid);
        $_SESSION['pay'][$pid]['step'] = 'otp';
        respond($pdo, $pid, '', sms_payload($method, $code));

    case 'card':
        if ($step !== 'card') {
            respond($pdo, $pid);
        }
        $number = preg_replace('/\D/', '', (string) ($_POST['cardno'] ?? ''));
        $name   = trim((string) ($_POST['cardname'] ?? ''));
        $expiry = (string) ($_POST['expiry'] ?? '');
        $cvv    = (string) ($_POST['cvv'] ?? '');
        $brand  = card_brand($number);
        $cvvLen = $brand === 'AMEX' ? 4 : 3;
        if (strlen($number) < 13 || strlen($number) > 19 || !luhn_ok($number)) {
            respond($pdo, $pid, 'The card number is not valid.');
        }
        if ($name === '' || !preg_match("/^[\\p{L} .'\\-]{2,60}$/u", $name)) {
            respond($pdo, $pid, 'Enter the name exactly as it appears on the card.');
        }
        if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiry, $m) || (int) ('20' . $m[2] . $m[1]) < (int) date('Ym')) {
            respond($pdo, $pid, 'The expiry date is not valid or the card has expired.');
        }
        if (!preg_match('/^\d{' . $cvvLen . '}$/', $cvv)) {
            respond($pdo, $pid, "The CVV must be $cvvLen digits.");
        }
        $charge = card_charge($number);
        if (!$charge['ok']) {
            fail_payment($pdo, $pid, $charge['message']);
            respond($pdo, $pid);
        }
        $_SESSION['pay'][$pid]['account'] = $brand . ' **** ' . substr($number, -4);
        $_SESSION['pay'][$pid]['step']    = 'card_otp';
        $code = new_code($pid);
        respond($pdo, $pid, '', sms_payload('Card', $code));

    case 'resend':
        if (!in_array($step, ['otp', 'card_otp'], true)) {
            respond($pdo, $pid);
        }
        if (time() < (int) ($st['resend_at'] ?? 0)) {
            respond($pdo, $pid, 'Please wait a few seconds before asking for a new code.');
        }
        $code = new_code($pid);
        respond($pdo, $pid, '', sms_payload($method, $code) + ['notice' => 'A new code has been sent.']);

    case 'otp':
        if (!in_array($step, ['otp', 'card_otp'], true)) {
            respond($pdo, $pid);
        }
        $code = implode('', array_map('strval', (array) ($_POST['d'] ?? [])));
        if (time() > (int) ($st['otp_exp'] ?? 0)) {
            respond($pdo, $pid, 'This code has expired. Tap "Resend code" to get a new one.');
        }
        if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string) $st['otp_hash'], hash('sha256', $pid . $code))) {
            $_SESSION['pay'][$pid]['tries'] = ($st['tries'] ?? 0) + 1;
            if ($_SESSION['pay'][$pid]['tries'] >= MAX_TRIES) {
                fail_payment($pdo, $pid, 'Too many wrong verification codes.');
                respond($pdo, $pid);
            }
            $left = MAX_TRIES - $_SESSION['pay'][$pid]['tries'];
            respond($pdo, $pid, "Wrong code. $left attempt" . ($left === 1 ? '' : 's') . ' left.');
        }
        unset($_SESSION['pay'][$pid]['otp_hash'], $_SESSION['pay'][$pid]['test_code']);
        if ($step === 'card_otp') {
            $r = complete_payment($pdo, $p, 'Card', $st['account']);
            respond($pdo, $pid, '', ['done' => $r['ok']]);
        }
        $_SESSION['pay'][$pid]['step']  = 'pin';
        $_SESSION['pay'][$pid]['tries'] = 0;
        respond($pdo, $pid);

    case 'pin':
        if ($step !== 'pin') {
            respond($pdo, $pid);
        }
        $pin = implode('', array_map('strval', (array) ($_POST['d'] ?? [])));
        $len = PAY_METHODS[$method]['pin'];
        if (!preg_match('/^\d{' . $len . '}$/', $pin)) {
            respond($pdo, $pid, "Enter your $len-digit PIN.");
        }
        $check = wallet_verify_pin($method, $st['wallet'], $pin, (float) $p['amount']);
        if (!$check['ok']) {
            if ($check['final']) {
                fail_payment($pdo, $pid, $check['message']);
                respond($pdo, $pid);
            }
            $_SESSION['pay'][$pid]['tries'] = ($st['tries'] ?? 0) + 1;
            if ($_SESSION['pay'][$pid]['tries'] >= MAX_TRIES) {
                fail_payment($pdo, $pid, 'Too many wrong PIN attempts.');
                respond($pdo, $pid);
            }
            $left = MAX_TRIES - $_SESSION['pay'][$pid]['tries'];
            respond($pdo, $pid, "Wrong PIN. $left attempt" . ($left === 1 ? '' : 's') . ' left.');
        }
        $r = complete_payment($pdo, $p, $method, mask_wallet($st['wallet']));
        respond($pdo, $pid, '', ['done' => $r['ok']]);

    default:
        respond($pdo, $pid, 'Unknown action.');
}
