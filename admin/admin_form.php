<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-management.php';
$user = require_super_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$form = ['name' => '', 'email' => ''];
if ($id) {
    $s = db()->prepare("SELECT id, name, email, role FROM users WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) { http_response_code(404); exit('Account not found.'); }
    if ($row['role'] !== 'event_admin') { http_response_code(403); exit('Only Event Admin accounts can be managed here.'); }
    $form = ['name' => $row['name'], 'email' => $row['email']];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Only these fields are read. The role is never taken from the request: accounts made here are
    // always Event Admins, whatever a forged form field says.
    $form['name'] = post_str('name', 100);
    $form['email'] = festora_normalize_email(post_str('email', 150));

    if ($id) {
        $result = admin_update_event_admin(db(), (int)$user['id'], $id, $form['name'], $form['email']);
    } else {
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        if ($password !== $password2) {
            $result = ['status' => 'invalid', 'errors' => ['Passwords do not match.']];
        } else {
            $result = admin_create_event_admin(db(), (int)$user['id'], $form['name'], $form['email'], $password);
        }
        unset($password, $password2);
    }
    if (in_array($result['status'], ['created', 'updated'], true)) {
        flash(...admin_status_message($result['status']));
        redirect($id ? 'admin/admins.php' : 'admin/admin_events.php?id=' . (int)$result['id']);
    }
    $errors = $result['status'] === 'invalid' ? $result['errors'] : [admin_status_message($result['status'])[0]];
}

$pageTitle = $id ? 'Edit Event Admin' : 'New Event Admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Super Admin console</p>
        <h1><?= $id ? 'Edit Event Admin' : 'Create Event Admin' ?></h1>
    </div>
    <a class="btn btn-outline" href="<?= url('admin/admins.php') ?>">Back</a>
</div>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<div class="form-card wide">
    <form method="post" novalidate data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" required minlength="2" maxlength="100" value="<?= e($form['name']) ?>">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required maxlength="150" value="<?= e($form['email']) ?>" autocomplete="off">
        <?php if (!$id): ?>
        <div class="row">
            <div>
                <label for="password">Initial password</label>
                <input type="password" id="password" name="password" required minlength="<?= FESTORA_ADMIN_PASSWORD_MIN ?>" maxlength="<?= FESTORA_ADMIN_PASSWORD_MAX ?>" autocomplete="new-password">
            </div>
            <div>
                <label for="password2">Confirm password</label>
                <input type="password" id="password2" name="password2" required autocomplete="new-password">
            </div>
        </div>
        <p class="lede"><?= FESTORA_ADMIN_PASSWORD_MIN ?>&ndash;<?= FESTORA_ADMIN_PASSWORD_MAX ?> characters with at least one letter and one digit. Share it through a private channel.</p>
        <?php endif; ?>
        <div class="actions">
            <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Create Event Admin' ?></button>
            <a class="btn btn-outline" href="<?= url('admin/admins.php') ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
