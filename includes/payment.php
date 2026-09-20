<?php
/**
 * Application-fee payments.
 *
 * PAYMENT_MODE = 'test' runs the full checkout flow (wallet number -> OTP ->
 * PIN, or card -> 3-D Secure code) against built-in test credentials, so no
 * real money moves. To go live, replace wallet_verify_pin() / card_charge()
 * with calls to the provider's merchant API (e.g. bKash Tokenized Checkout
 * "create" + "execute", Nagad / Rocket merchant APIs or a card gateway such as
 * SSLCommerz). Everything else (records, invoices, application creation)
 * stays the same.
 */

const PAY_METHODS = [
    'bKash'  => ['key' => 'bkash',  'logo' => 'bKash',  'sub' => 'Mobile wallet',  'pin' => 5],
    'Nagad'  => ['key' => 'nagad',  'logo' => 'Nagad',  'sub' => 'Mobile wallet',  'pin' => 4],
    'Rocket' => ['key' => 'rocket', 'logo' => 'Rocket', 'sub' => 'Mobile wallet',  'pin' => 4],
    'Card'   => ['key' => 'card',   'logo' => 'CARD',   'sub' => 'Visa, Mastercard, Amex', 'pin' => 0],
];

// Test-mode credentials (shown on the checkout page while PAYMENT_MODE is 'test')
const TEST_PINS = ['bKash' => '12121', 'Nagad' => '1234', 'Rocket' => '1234'];
const TEST_LOW_BALANCE_WALLET = '01700000000';            // always "insufficient balance"
const TEST_CARD_OK       = '4242424242424242';            // approved
const TEST_CARD_DECLINED = '4000000000000002';            // declined by the bank

const CHECKOUT_MINUTES = 10;   // a checkout expires after this many minutes
const OTP_SECONDS      = 180;  // a verification code is valid for 3 minutes
const RESEND_SECONDS   = 30;   // wait before a new code can be requested
const MAX_TRIES        = 3;    // wrong codes / PINs before the payment fails

function pay_method_key(?string $method): string
{
    return PAY_METHODS[$method]['key'] ?? 'card';
}

function method_badge(?string $method): string
{
    if (!$method) {
        return '<span class="text-muted">&mdash;</span>';
    }
    return '<span class="method-badge"><span class="method-dot m-' . e(pay_method_key($method)) . '"></span>' . e($method) . '</span>';
}

function mask_wallet(string $number): string
{
    return substr($number, 0, 3) . str_repeat('*', 6) . substr($number, -2);
}

function valid_bd_mobile(string $number): bool
{
    return (bool) preg_match('/^01[3-9]\d{8}$/', $number);
}

function luhn_ok(string $digits): bool
{
    $sum = 0;
    $alt = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int) $digits[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $digits !== '' && $sum % 10 === 0;
}

function card_brand(string $digits): string
{
    return match (true) {
        (bool) preg_match('/^4/', $digits)              => 'VISA',
        (bool) preg_match('/^(5[1-5]|2[2-7])/', $digits) => 'MASTERCARD',
        (bool) preg_match('/^3[47]/', $digits)          => 'AMEX',
        default                                         => 'CARD',
    };
}

/** Transaction ID in the style mobile wallets use: 10 characters, starts with a method letter. */
function new_trx_id(PDO $pdo, string $method): string
{
    $prefix = ['bKash' => 'B', 'Nagad' => 'N', 'Rocket' => 'R', 'Card' => 'C'][$method] ?? 'T';
    $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $id = $prefix;
        for ($i = 0; $i < 9; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $q = $pdo->prepare('SELECT 1 FROM payment WHERE trxid = ?');
        $q->execute([$id]);
    } while ($q->fetch());
    return $id;
}

function load_payment(PDO $pdo, string $pid): array|false
{
    $q = $pdo->prepare("
        SELECT p.*, sc.title, sc.type, sc.deadline, sc.totalslots, sc.amount AS award,
               s.name AS student_name, s.email, s.phone, s.userid, d.deptname,
               TIMESTAMPDIFF(SECOND, NOW(), p.createdat + INTERVAL " . CHECKOUT_MINUTES . " MINUTE) AS seconds_left
        FROM payment p
        JOIN scholarship sc ON sc.scholarshipid = p.scholarshipid
        JOIN student s      ON s.studentid = p.studentid
        JOIN department d   ON d.deptid = s.deptid
        WHERE p.paymentid = ?");
    $q->execute([$pid]);
    return $q->fetch();
}

/** Marks an unfinished checkout as Expired once its time is up. Returns the fresh row. */
function expire_if_needed(PDO $pdo, array $p): array
{
    if ($p['status'] === 'Initiated' && (int) $p['seconds_left'] <= 0) {
        $pdo->prepare("UPDATE payment SET status = 'Expired', failreason = 'Checkout time ran out.' WHERE paymentid = ? AND status = 'Initiated'")
            ->execute([$p['paymentid']]);
        unset($_SESSION['pay'][$p['paymentid']]);
        $p['status'] = 'Expired';
        $p['failreason'] = 'Checkout time ran out.';
    }
    return $p;
}

/** Starts (or re-uses) a checkout for a student + scholarship. Returns the payment ID. */
function start_checkout(PDO $pdo, string $sid, array $sch): string
{
    $q = $pdo->prepare("SELECT paymentid FROM payment
        WHERE studentid = ? AND scholarshipid = ? AND status = 'Initiated'
          AND createdat > NOW() - INTERVAL " . CHECKOUT_MINUTES . " MINUTE
        ORDER BY createdat DESC LIMIT 1");
    $q->execute([$sid, $sch['scholarshipid']]);
    if ($pid = $q->fetchColumn()) {
        return $pid;
    }
    // older unfinished checkouts for the same scholarship are closed
    $pdo->prepare("UPDATE payment SET status = 'Expired', failreason = 'Replaced by a new checkout.'
        WHERE studentid = ? AND scholarshipid = ? AND status = 'Initiated'")->execute([$sid, $sch['scholarshipid']]);

    $pdo->prepare('INSERT INTO payment (studentid, scholarshipid, amount) VALUES (?, ?, ?)')
        ->execute([$sid, $sch['scholarshipid'], $sch['applicationfee']]);
    $n = $pdo->prepare("SELECT paymentid FROM payment WHERE studentid = ? AND scholarshipid = ? AND status = 'Initiated' ORDER BY paymentid DESC LIMIT 1");
    $n->execute([$sid, $sch['scholarshipid']]);
    return (string) $n->fetchColumn();
}

function fail_payment(PDO $pdo, string $pid, string $reason): void
{
    $pdo->prepare("UPDATE payment SET status = 'Failed', failreason = ? WHERE paymentid = ? AND status = 'Initiated'")
        ->execute([$reason, $pid]);
    unset($_SESSION['pay'][$pid]);
}

/**
 * Money has been taken: create the application and mark the payment Completed
 * in ONE transaction. If the application can no longer be accepted (deadline
 * passed, slots filled, already applied) the payment is marked Failed instead.
 * Returns ['ok' => bool, 'message' => string, 'appid' => ?string].
 */
function complete_payment(PDO $pdo, array $p, string $method, string $account): array
{
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT totalslots, deadline FROM scholarship WHERE scholarshipid = ? FOR UPDATE');
        $lock->execute([$p['scholarshipid']]);
        $sch = $lock->fetch();

        $cur = $pdo->prepare('SELECT status FROM payment WHERE paymentid = ? FOR UPDATE');
        $cur->execute([$p['paymentid']]);
        if ($cur->fetchColumn() !== 'Initiated') {
            throw new RuntimeException('This checkout is no longer active.');
        }
        $dup = $pdo->prepare('SELECT appid FROM application WHERE studentid = ? AND scholarshipid = ?');
        $dup->execute([$p['studentid'], $p['scholarshipid']]);
        if ($dup->fetch()) {
            throw new RuntimeException('You have already applied for this scholarship.');
        }
        if ($sch['deadline'] < date('Y-m-d')) {
            throw new RuntimeException('The application deadline has passed.');
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE scholarshipid = ? AND status = 'Approved'");
        $cnt->execute([$p['scholarshipid']]);
        if ((int) $cnt->fetchColumn() >= (int) $sch['totalslots']) {
            throw new RuntimeException('All slots for this scholarship have been filled.');
        }

        $pdo->prepare('INSERT INTO application (studentid, scholarshipid) VALUES (?, ?)')->execute([$p['studentid'], $p['scholarshipid']]);
        $a = $pdo->prepare('SELECT appid FROM application WHERE studentid = ? AND scholarshipid = ?');
        $a->execute([$p['studentid'], $p['scholarshipid']]);
        $appid = (string) $a->fetchColumn();

        $pdo->prepare("UPDATE payment SET status = 'Completed', method = ?, account = ?, trxid = ?, appid = ?, paidat = NOW() WHERE paymentid = ?")
            ->execute([$method, $account, new_trx_id($pdo, $method), $appid, $p['paymentid']]);
        $pdo->commit();
        unset($_SESSION['pay'][$p['paymentid']]);
        return ['ok' => true, 'message' => 'Payment successful.', 'appid' => $appid];
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail_payment($pdo, $p['paymentid'], $ex->getMessage());
        return ['ok' => false, 'message' => $ex->getMessage(), 'appid' => null];
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'The payment could not be completed. Please try again.', 'appid' => null];
    }
}

/* ---------------- provider stubs (test mode) ---------------- */

/** Wallet PIN check. Live mode: provider "execute payment" call. */
function wallet_verify_pin(string $method, string $wallet, string $pin, float $amount): array
{
    if ($wallet === TEST_LOW_BALANCE_WALLET) {
        return ['ok' => false, 'final' => true, 'message' => 'Insufficient balance in this ' . $method . ' account.'];
    }
    if (!hash_equals(TEST_PINS[$method] ?? '', $pin)) {
        return ['ok' => false, 'final' => false, 'message' => 'Wrong PIN. Please try again.'];
    }
    return ['ok' => true, 'final' => false, 'message' => ''];
}

/** Card authorisation. Live mode: card gateway call. */
function card_charge(string $number): array
{
    if ($number === TEST_CARD_DECLINED) {
        return ['ok' => false, 'message' => 'Your card was declined by the issuing bank.'];
    }
    return ['ok' => true, 'message' => ''];
}
