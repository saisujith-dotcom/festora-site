<?php
if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    exit(2);
}

$mode = $argv[1];
$sessionDirectory = $argv[2] ?? sys_get_temp_dir();
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    exit(3);
}
session_save_path($sessionDirectory);

if (str_starts_with($mode, 'https-')) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTPS'] = $mode === 'https-direct' ? 'on' : 'off';
    $_SERVER['REMOTE_ADDR'] = in_array($mode, ['https-trusted', 'https-forwarded-list'], true)
        ? '192.0.2.10' : '192.0.2.11';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = $mode === 'https-forwarded-list' ? 'https,http' : 'https';
    require __DIR__ . '/../includes/bootstrap.php';
    $params = session_get_cookie_params();
    echo json_encode([
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    exit(0);
}

if ($mode === 'helpers') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require __DIR__ . '/../includes/bootstrap.php';
    $_POST['probe'] = '  value  ';
    $ok = valid_time('23:59:59')
        && !valid_time('24:00')
        && valid_image_url('https://example.test/image.png')
        && !valid_image_url('javascript:alert(1)')
        && e('<script>') === '&lt;script&gt;'
        && post_str('probe') === 'value';
    echo $ok ? 'HELPERS_OK' : 'HELPERS_FAIL';
    exit($ok ? 0 : 1);
}

if ($mode === 'rate-limit') {
    require __DIR__ . '/../includes/ratelimit.php';
    $attempts = [];
    for ($i = 0; $i < 6; $i++) {
        $attempts[] = rl_login_attempt('regression@example.invalid', 'v4:c0000201')['allowed'];
    }
    $ok = $attempts === [true, true, true, true, true, false];
    echo $ok ? 'RATE_LIMIT_OK' : 'RATE_LIMIT_FAIL';
    exit($ok ? 0 : 1);
}

if ($mode === 'rate-limit-docroot-state') {
    $_SERVER['DOCUMENT_ROOT'] = (string)getenv('FESTORA_TEST_DOCUMENT_ROOT');
    require __DIR__ . '/../includes/ratelimit.php';
    try {
        echo 'STATE=' . rl_state_dir();
    } catch (RateLimitUnavailable $error) {
        echo 'STATE_DENIED';
    }
    exit(0);
}

if ($mode === 'log-sanitize') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require __DIR__ . '/../includes/bootstrap.php';
    festora_log_error('registration', new PDOException('private-path password-token@example.invalid session-secret', '23000'));
    echo 'LOG_PROBE_COMPLETE';
    exit(0);
}

if (!in_array($mode, ['logout-get', 'logout-missing-csrf', 'logout-valid', 'student-admin'], true)) {
    exit(4);
}

$sessionId = bin2hex(random_bytes(16));
session_id($sessionId);
session_start();
$_SESSION = [
    'user' => ['id' => 91, 'name' => 'Regression Student', 'email' => 'student@example.invalid', 'role' => 'student'],
    'csrf' => str_repeat('a', 64),
];
session_write_close();
$_COOKIE[session_name()] = $sessionId;
$_SERVER['SCRIPT_NAME'] = $mode === 'student-admin' ? '/admin/index.php' : '/logout.php';
$_SERVER['REQUEST_METHOD'] = $mode === 'logout-get' ? 'GET' : 'POST';
$_POST = $mode === 'logout-valid' ? ['csrf' => str_repeat('a', 64)] : [];

register_shutdown_function(static function () use ($sessionId): void {
    echo "\nPROBE_STATUS=" . http_response_code();
    echo "\nPROBE_USER=" . (isset($_SESSION['user']) ? 'present' : 'absent');
    echo "\nPROBE_CSRF=" . (isset($_SESSION['csrf']) ? 'present' : 'absent');
    echo "\nPROBE_FLASH=" . (isset($_SESSION['flash']) ? 'present' : 'absent');
    echo "\nPROBE_ROTATED=" . (session_id() !== $sessionId ? 'yes' : 'no');
});

if ($mode === 'student-admin') {
    require __DIR__ . '/../includes/bootstrap.php';
    require_organizer();
}

require __DIR__ . '/../logout.php';
