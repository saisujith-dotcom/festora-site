<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) redirect('index.php');

$errors = [];
$old = ['name' => '', 'email' => '', 'enrollment_no' => '', 'branch' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['name'] = post_str('name', 100);
    $old['email'] = strtolower(post_str('email', 150));
    $old['enrollment_no'] = post_str('enrollment_no', 30);
    $old['branch'] = post_str('branch', 60);
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (mb_strlen($old['name']) < 2) $errors[] = 'Please enter your full name.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    elseif (!festora_is_development() && is_demo_email($old['email'])) $errors[] = 'This email domain is reserved. Please use your own email address.';
    if ($old['enrollment_no'] === '') $errors[] = 'Enrollment number is required.';
    elseif (!preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', $old['enrollment_no'])) $errors[] = 'Enrollment number may contain letters, digits, - and / only.';
    if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) $errors[] = 'Password must be at least 8 characters with a letter and a digit.';
    if ($pass !== $pass2) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $s = db()->prepare("SELECT id FROM users WHERE email=?");
        $s->execute([$old['email']]);
        if ($s->fetch()) {
            $errors[] = 'An account with this email already exists.';
        } else {
            db()->prepare("INSERT INTO users (name,email,password_hash,role,enrollment_no,branch) VALUES (?,?,?,'student',?,?)")
                ->execute([$old['name'], $old['email'], password_hash($pass, PASSWORD_DEFAULT), $old['enrollment_no'], $old['branch'] ?: null]);
            flash('Account created. Please log in.');
            redirect('login.php');
        }
    }
}
$pageTitle = 'Sign up';
$authLayout = true;
$authLabel = 'Student sign-up';
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow">Student access</p>
<h1>Create student account</h1>
<p class="lede">Register once to sign up for events and manage your registrations.</p>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<form class="auth-form" method="post" novalidate data-validate>
    <?= csrf_field() ?>
    <label for="name">Full name</label>
    <input type="text" id="name" name="name" required minlength="2" value="<?= e($old['name']) ?>">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required value="<?= e($old['email']) ?>">
    <div class="row">
        <div>
            <label for="enrollment_no">Enrollment number</label>
            <input type="text" id="enrollment_no" name="enrollment_no" required pattern="[A-Za-z0-9\-\/]{4,30}" value="<?= e($old['enrollment_no']) ?>">
        </div>
        <div>
            <label for="branch">Branch (optional)</label>
            <input type="text" id="branch" name="branch" value="<?= e($old['branch']) ?>">
        </div>
    </div>
    <div class="row">
        <div>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label for="password2">Confirm password</label>
            <input type="password" id="password2" name="password2" required autocomplete="new-password">
        </div>
    </div>
    <button class="btn btn-lg btn-block btn-split" type="submit"><span>Sign up</span> <?= $navArrow ?></button>
</form>
<p class="auth-alt">Already registered? <a href="<?= url('login.php') ?>">Log in</a></p>
<?php require __DIR__ . '/includes/footer.php'; ?>
