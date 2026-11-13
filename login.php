<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) redirect('index.php');

$error = '';
$email = '';
// Only allow redirecting to local relative paths.
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_=&\-]*)?$#', $next)) $next = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(post_str('email', 150));
    $pass = (string)($_POST['password'] ?? '');

    // Persistent rate limit. One attempt is consumed BEFORE the password is checked; if the
    // limiter's storage is unavailable we fail closed (no authentication is attempted at all).
    require_once __DIR__ . '/includes/ratelimit.php';
    $rl = null;
    try {
        $rl = rl_login_attempt($email, rl_client_id());
    } catch (Throwable $ex) {
        festora_log_error('login_rate_limiter', $ex);
        http_response_code(503);
        $error = 'Login is temporarily unavailable. Please try again later.';
    }
    if ($rl !== null && !$rl['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $rl['retry_after']);
        $error = 'Too many login attempts. Please try again in about ' . max(1, (int)ceil($rl['retry_after'] / 60)) . ' minute(s).';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $s = db()->prepare("SELECT * FROM users WHERE email=?");
    $s->execute([$email]);
    $u = $s->fetch();
    // Demo accounts (@festora.test) work only when FESTORA_ENV=development. The stored address is
    // checked too, because the database collation is accent-insensitive.
    $demoBlocked = !festora_is_development() && (is_demo_email($email) || ($u && is_demo_email((string)$u['email'])));
    // Always run one hash check so response time does not reveal whether the email exists.
    $hash = ($u && !$demoBlocked) ? $u['password_hash'] : '$2y$12$nbcDnKBmgbi.IlPBK8l9NOQBqLOZJZwHR9wwWqs2Tcc3GB3XqhNiW';
    $passOk = password_verify($pass, $hash);
    if ($u && $passOk && !$demoBlocked) {
        rl_login_success($rl['token']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
        if ($next) redirect($next);
        redirect($u['role'] === 'organizer' ? 'admin/index.php' : 'index.php');
    }
    $error = 'Invalid email or password.';
}
$pageTitle = 'Login';
$authLayout = true;
$authLabel = 'Account access';
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow">Welcome back</p>
<h1>Log in</h1>
<p class="lede">Students and organisers sign in with their Festora email.</p>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form class="auth-form" method="post" novalidate data-validate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required value="<?= e($email) ?>" autocomplete="email">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <button class="btn btn-lg btn-block btn-split" type="submit"><span>Log in</span> <?= $navArrow ?></button>
</form>
<p class="auth-alt">New here? <a href="<?= url('signup.php') ?>">Create a student account</a></p>
<?php require __DIR__ . '/includes/footer.php'; ?>
