<?php
require_once __DIR__ . '/../config.php';

// Security headers (sent on every page).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Sessions: only accept server-issued IDs, and only via cookies.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

function festora_request_is_https(): bool {
    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    if ($https !== '' && !in_array($https, ['off', '0', 'false'], true)) {
        return true;
    }

    $configured = getenv('FESTORA_TRUSTED_PROXY_IPS');
    if (!is_string($configured) || trim($configured) === '') {
        return false;
    }

    static $invalidConfigurationLogged = false;
    $trusted = [];
    foreach (explode(',', $configured) as $address) {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            if (!$invalidConfigurationLogged) {
                error_log('festora invalid trusted-proxy IP configuration');
                $invalidConfigurationLogged = true;
            }
            return false;
        }
        $trusted[] = $address;
    }

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return in_array($remote, $trusted, true) && $forwardedProto === 'https';
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => festora_request_is_https(),
]);
session_start();

// Idle timeout: end the session after 2 hours without activity.
define('SESSION_IDLE_SECONDS', 7200);
$__wasLoggedIn = !empty($_SESSION['user']);
if (isset($_SESSION['last_active']) && time() - (int)$_SESSION['last_active'] > SESSION_IDLE_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
    if ($__wasLoggedIn) $_SESSION['flash'] = ['msg' => 'Your session expired. Please log in again.', 'type' => 'error'];
}
$_SESSION['last_active'] = time();
unset($__wasLoggedIn);

// is_demo_email(), the role helpers and the account validators are shared with the CLI utilities, so they
// live in account-validation.php. It must load before the demo-session check below.
require_once __DIR__ . '/account-validation.php';

// Demo accounts only work when FESTORA_ENV=development. Drop any demo session otherwise.
if (!festora_is_development() && is_demo_email((string)($_SESSION['user']['email'] ?? ''))) {
    unset($_SESSION['user']);
    session_regenerate_id(true);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                festora_db_dsn(DB_NAME),
                DB_USER, DB_PASS,
                festora_db_pdo_options()
            );
        } catch (PDOException $e) {
            festora_log_error('database_connection', $e);
            http_response_code(500);
            exit('Database connection failed. Please try again later.');
        }
    }
    return $pdo;
}

function festora_log_error(string $context, Throwable $error): void {
    $context = preg_replace('/[^A-Za-z0-9_.-]/', '_', $context);
    $entry = 'festora ' . $context . ' error=' . get_class($error);
    if ($error instanceof PDOException) {
        $entry .= ' sqlstate=' . preg_replace('/[^A-Za-z0-9]/', '', (string)$error->getCode());
    }
    error_log($entry);
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** True for an http(s) image link that is safe to store: no spaces, quotes, parentheses, backslashes, backticks or angle brackets. */
function valid_image_url(string $u): bool {
    return $u !== ''
        && preg_match('#^https?://[^\s"\'()\\\\<>`]+$#i', $u) === 1
        && filter_var($u, FILTER_VALIDATE_URL) !== false;
}

/** Safe CSS value for background-image: a quoted, percent-encoded url("..."), or 'none' when $u is not an http(s) URL. */
function css_url(?string $u): string {
    $u = trim((string)$u);
    if ($u === '' || !preg_match('#^https?://\S#i', $u)) return 'none';
    $u = preg_replace_callback('/[^A-Za-z0-9\-._~:\/?#\[\]@!$&*+,;=%]/', fn($m) => rawurlencode($m[0]), $u);
    return 'url("' . $u . '")';
}
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void {
    $sent  = $_POST['csrf'] ?? null;
    $known = $_SESSION['csrf'] ?? '';
    $ok = is_string($sent) && is_string($known) && $known !== '' && hash_equals($known, $sent);
    if (!$ok) { http_response_code(419); exit('Invalid or expired form. Go back, refresh and try again.'); }
}

function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Per-request cache for current_user(), keyed by session id + account id. */
function current_user(): ?array {
    static $cache = null;
    $session = $_SESSION['user'] ?? null;
    if (!is_array($session) || !isset($session['id']) || !is_numeric($session['id'])) {
        $cache = null;
        return null;
    }

    $key = session_id() . ':' . (int)$session['id'];
    if ($cache !== null && $cache['key'] === $key) {
        return $cache['user'];
    }

    // The session only remembers WHO is logged in. Whether that account still exists, is active and what
    // role it holds is read from the database on every authenticated request, so a deactivation, deletion
    // or role change takes effect on the very next request.
    $statement = db()->prepare('SELECT id, name, email, role, is_active FROM users WHERE id=? LIMIT 1');
    $statement->execute([(int)$session['id']]);
    $row = $statement->fetch();

    $valid = is_array($row)
        && (int)$row['is_active'] === 1
        && in_array($row['role'], FESTORA_ACCOUNT_ROLES, true);
    if (!$valid) {
        $deactivated = is_array($row) && (int)$row['is_active'] !== 1;
        unset($_SESSION['user']);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        flash(
            $deactivated
                ? 'This account has been deactivated. Please contact a Super Admin.'
                : 'Your session is no longer valid. Please log in again.',
            'error'
        );
        $cache = null;
        return null;
    }

    $user = ['id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['role']];
    $_SESSION['user'] = $user;
    $cache = ['key' => $key, 'user' => $user];
    return $user;
}
function is_admin(): bool { return is_admin_role(current_user()['role'] ?? null); }
function is_super_admin(): bool { return is_super_admin_role(current_user()['role'] ?? null); }

function require_login(): array {
    $u = current_user();
    if (!$u) {
        if (!isset($_SESSION['flash'])) flash('Please log in to continue.', 'error');
        redirect('login.php');
    }
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if (!is_admin_role($u['role'] ?? null)) { http_response_code(403); exit('Access denied: administrators only.'); }
    return $u;
}
function require_super_admin(): array {
    $u = require_admin();
    if (!is_super_admin_role($u['role'])) { http_response_code(403); exit('Access denied: Super Admins only.'); }
    return $u;
}

require_once __DIR__ . '/permissions.php';

function valid_time(string $time): bool {
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time) === 1;
}

function fmt_date(string $d): string { return date('D, d M Y', strtotime($d)); }
function fmt_time(?string $t): string { return $t ? date('g:i A', strtotime($t)) : ''; }

/** Number of active registrations for an event. */
function seats_taken(int $eventId): int {
    $s = db()->prepare("SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='registered'");
    $s->execute([$eventId]);
    return (int)$s->fetchColumn();
}

function post_str(string $k, int $max = 255): string {
    return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
}

set_exception_handler(static function (Throwable $error): void {
    festora_log_error('uncaught_exception', $error);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('An unexpected error occurred. Please try again later.');
});
