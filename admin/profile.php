<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$aid = $_SESSION['adminid'];
function load_admin(PDO $pdo, string $aid): array
{
    $q = $pdo->prepare('SELECT a.*, u.username, u.createdat, u.lastlogin FROM admin a JOIN users u ON u.userid = a.userid WHERE a.adminid = ?');
    $q->execute([$aid]);
    return $q->fetch();
}
$me = load_admin($pdo, $aid);
$form = ['name' => $me['name'], 'email' => (string) $me['email'], 'phone' => (string) $me['phone'], 'designation' => (string) $me['designation']];
$profileErrors = [];
$passwordErrors = [];

if (isPost()) {
    $action = (string) ($_POST['action'] ?? '');
    if (!verifyCsrfToken()) {
        finish('danger', 'Your session expired. Please reload the page and try again.', 'admin/profile.php', false);
    }
    if ($action === 'photo') {
        [$file, $err] = save_avatar($_FILES['photo'] ?? []);
        if ($err) {
            finish('danger', $err, 'admin/profile.php', false);
        }
        delete_avatar($me['photo']);
        $pdo->prepare('UPDATE admin SET photo = ? WHERE adminid = ?')->execute([$file, $aid]);
        $_SESSION['photo'] = $file;
        finish('success', 'Profile photo updated.', 'admin/profile.php', false, ['photo' => BASE_URL . 'uploads/avatars/' . $file]);
    }
    if ($action === 'profile') {
        foreach ($form as $k => $_) {
            $form[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        $profileErrors = collect_errors(
            v_name($form['name'], 'Full name'),
            $form['email'] === '' ? '' : v_email($form['email']),
            v_phone($form['phone']),
            v_text($form['designation'], 'Designation', 60, false)
        );
        if (!$profileErrors && $form['email'] !== '') {
            $dup = $pdo->prepare('SELECT 1 FROM admin WHERE email = ? AND adminid <> ?');
            $dup->execute([$form['email'], $aid]);
            if ($dup->fetch()) {
                $profileErrors[] = 'Another admin already uses this email.';
            }
        }
        if (!$profileErrors) {
            $n = fn($v) => $v === '' ? null : $v;
            $pdo->prepare('UPDATE admin SET name = ?, email = ?, phone = ?, designation = ? WHERE adminid = ?')
                ->execute([$form['name'], $n($form['email']), $n($form['phone']), $n($form['designation']), $aid]);
            $_SESSION['name'] = $form['name'];
            finish('success', 'Your profile has been updated.', 'admin/profile.php');
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['password'] ?? '');
        $h = $pdo->prepare('SELECT passwordhash FROM users WHERE userid = ?');
        $h->execute([$_SESSION['userid']]);
        if (!password_verify($current, (string) $h->fetchColumn())) {
            $passwordErrors[] = 'Your current password is incorrect.';
        }
        if ($msg = v_password($new, (string) ($_POST['confirm_password'] ?? ''))) {
            $passwordErrors[] = $msg;
        }
        if (!$passwordErrors && $new === $current) {
            $passwordErrors[] = 'The new password must be different from the current one.';
        }
        if (!$passwordErrors) {
            $pdo->prepare('UPDATE users SET passwordhash = ? WHERE userid = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['userid']]);
            $pdo->prepare('DELETE FROM remember_token WHERE userid = ?')->execute([$_SESSION['userid']]);
            if (isset($_COOKIE[COOKIE_REMEMBER])) {
                issue_remember_cookie($pdo, $_SESSION['userid']);
            }
            finish('success', 'Your password has been changed.', 'admin/profile.php', false);
        }
    }
}
ajax_errors($profileErrors ?: $passwordErrors);

$w = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM application WHERE approvedby = ? AND status = 'Approved') AS approved,
    (SELECT COUNT(*) FROM application WHERE approvedby = ? AND status = 'Rejected') AS rejected,
    (SELECT COUNT(*) FROM scholarship WHERE createdby = ?) AS created");
$w->execute([$aid, $aid, $aid]);
$work = $w->fetch();
$recent = $pdo->prepare("SELECT a.appid, a.status, a.applydate, s.name, sc.title FROM application a
    JOIN student s ON s.studentid = a.studentid JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid
    WHERE a.approvedby = ? ORDER BY a.applydate DESC LIMIT 8");
$recent->execute([$aid]);
$recent = $recent->fetchAll();
$val = fn($v) => $v !== null && $v !== '' ? e($v) : '<span class="text-muted">Not added</span>';

page_start('My Profile', 'Admin', 'profile');
?>
<div class="profile-hero">
    <div class="profile-cover"></div>
    <div class="profile-body">
        <div class="avatar-wrap">
            <?= avatar_html($me['photo'], $me['name'], 'avatar-xl') ?>
            <form method="POST" action="profile.php" enctype="multipart/form-data" data-ajax class="no-print">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="photo">
                <label class="avatar-edit" title="Change photo" aria-label="Change profile photo">
                    <i class="bi bi-camera"></i>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="visually-hidden" data-autosubmit>
                </label>
                <button type="submit" hidden></button>
            </form>
        </div>
        <div class="min-w-0">
            <h1 class="profile-name"><?= e($me['name']) ?></h1>
            <div class="profile-meta">
                <span><i class="bi bi-shield-check"></i><?= e($me['adminid']) ?></span>
                <span><i class="bi bi-briefcase"></i><?= $val($me['designation']) ?></span>
                <span><i class="bi bi-clock"></i>Last sign-in <?= $me['lastlogin'] ? date('d M Y, h:i A', strtotime($me['lastlogin'])) : '—' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-icon tone-deep"><i class="bi bi-check2-circle"></i></span><div><div class="stat-label">Approved by you</div><div class="stat-value"><?= (int) $work['approved'] ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-amber"><i class="bi bi-x-circle"></i></span><div><div class="stat-label">Rejected by you</div><div class="stat-value"><?= (int) $work['rejected'] ?></div></div></div>
    <div class="stat-card"><span class="stat-icon"><i class="bi bi-award"></i></span><div><div class="stat-label">Scholarships created</div><div class="stat-value"><?= (int) $work['created'] ?></div></div></div>
</div>

<div class="content-card">
    <div class="nav-tabs-clean" role="tablist" data-tabs>
        <button type="button" class="active" data-tab="overview" role="tab" aria-selected="true"><i class="bi bi-person-lines-fill"></i> Overview</button>
        <button type="button" data-tab="edit" role="tab" aria-selected="false"><i class="bi bi-pencil-square"></i> Edit profile</button>
        <button type="button" data-tab="security" role="tab" aria-selected="false"><i class="bi bi-shield-lock"></i> Security</button>
    </div>
    <section class="tab-pane-clean active" id="overview">
        <div class="info-grid mb-4">
            <div class="info-item"><div class="k">Full name</div><div class="v"><?= e($me['name']) ?></div></div>
            <div class="info-item"><div class="k">Username</div><div class="v"><?= e($me['username']) ?></div></div>
            <div class="info-item"><div class="k">Email</div><div class="v"><?= $val($me['email']) ?></div></div>
            <div class="info-item"><div class="k">Mobile</div><div class="v"><?= $val($me['phone']) ?></div></div>
            <div class="info-item"><div class="k">Designation</div><div class="v"><?= $val($me['designation']) ?></div></div>
            <div class="info-item"><div class="k">Account created</div><div class="v"><?= fmt_date($me['createdat']) ?></div></div>
        </div>
        <h2 class="card-heading">Your recent decisions</h2>
        <?php if (!$recent): ?>
            <div class="empty-state"><i class="bi bi-clipboard-check"></i>You have not reviewed any application yet.</div>
        <?php else: ?>
        <ul class="timeline">
            <?php foreach ($recent as $r): ?>
            <li><div><?= status_badge($r['status']) ?> <a class="id-pill" href="review.php?id=<?= urlencode($r['appid']) ?>"><?= e($r['appid']) ?></a> <?= e($r['name']) ?> &middot; <?= e($r['title']) ?></div>
                <div class="when">Applied <?= fmt_date($r['applydate']) ?></div></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <section class="tab-pane-clean" id="edit">
        <?= error_list($profileErrors) ?>
        <form method="POST" action="profile.php" class="row g-3 needs-validation" novalidate data-ajax>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="profile">
            <div class="col-md-6">
                <label class="form-label" for="name">Full name<span class="req">*</span></label>
                <input type="text" id="name" name="name" class="form-control" value="<?= e($form['name']) ?>" minlength="2" maxlength="100" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="designation">Designation</label>
                <input type="text" id="designation" name="designation" class="form-control" value="<?= e($form['designation']) ?>" maxlength="60" placeholder="e.g. Scholarship Officer">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Work email</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= e($form['email']) ?>" maxlength="100">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="phone">Mobile number</label>
                <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($form['phone']) ?>" pattern="01[3-9][0-9]{8}" maxlength="11" placeholder="01XXXXXXXXX" inputmode="numeric">
                <div class="invalid-feedback">11-digit mobile number.</div>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary" data-busy="Saving…"><i class="bi bi-check-lg"></i> Save changes</button></div>
        </form>
    </section>
    <section class="tab-pane-clean" id="security">
        <div style="max-width: 460px">
            <?= error_list($passwordErrors) ?>
            <form method="POST" action="profile.php" class="needs-validation" novalidate data-ajax data-reset>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="password">
                <div class="mb-3"><label class="form-label" for="current_password">Current password<span class="req">*</span></label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password"></div>
                <div class="mb-3"><label class="form-label" for="password">New password<span class="req">*</span></label>
                    <div class="password-field"><input type="password" id="password" name="password" class="form-control" minlength="6" maxlength="72" required autocomplete="new-password">
                    <button type="button" class="password-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
                <div class="mb-3"><label class="form-label" for="confirm_password">Confirm new password<span class="req">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="6" maxlength="72" required autocomplete="new-password">
                    <div class="invalid-feedback">Passwords must match.</div></div>
                <button type="submit" class="btn btn-primary" data-busy="Updating…"><i class="bi bi-key"></i> Update password</button>
            </form>
        </div>
    </section>
</div>
<?php page_end(true); ?>
