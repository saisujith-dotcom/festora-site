<?php
// Database settings. Real values live in config.local.php (git-ignored) or in the
// FESTORA_DB_* environment variables. The application fails closed if the required
// database configuration is not provided explicitly, so it never silently falls back
// to a root account with an empty password in production.
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

$__festoraDbEnvMap = [
    'DB_HOST' => 'FESTORA_DB_HOST',
    'DB_PORT' => 'FESTORA_DB_PORT',
    'DB_NAME' => 'FESTORA_DB_NAME',
    'DB_USER' => 'FESTORA_DB_USER',
    'DB_PASS' => 'FESTORA_DB_PASS',
    'DB_SSL_CA' => 'FESTORA_DB_SSL_CA',
];
foreach ($__festoraDbEnvMap as $name => $envName) {
    if (!defined($name)) {
        $value = getenv($envName);
        if (is_string($value) && trim($value) !== '') {
            define($name, $value);
        }
    }
}
unset($__festoraDbEnvMap);

if (!defined('DB_HOST') || trim((string)DB_HOST) === ''
    || !defined('DB_NAME') || trim((string)DB_NAME) === ''
    || !defined('DB_USER') || trim((string)DB_USER) === ''
    || !defined('DB_PASS') || trim((string)DB_PASS) === '') {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('Database configuration is unavailable.');
}

if (!defined('DB_PORT')) {
    $__port = getenv('FESTORA_DB_PORT');
    define('DB_PORT', is_string($__port) && trim($__port) !== '' ? trim($__port) : 4000);
    unset($__port);
}
$__port = DB_PORT;
if ((!is_int($__port) && !is_string($__port))
    || preg_match('/^[0-9]+$/D', trim((string)$__port)) !== 1
    || (int)$__port < 1 || (int)$__port > 65535) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('Database configuration is invalid.');
}
unset($__port);

$__caConfigured = defined('DB_SSL_CA') ? DB_SSL_CA : '';
if (!is_string($__caConfigured) && !is_int($__caConfigured)) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('Database TLS configuration is invalid.');
}
$__caConfigured = trim((string)$__caConfigured);
if ($__caConfigured !== ''
    && (!is_file($__caConfigured) || !is_readable($__caConfigured))) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('Database TLS configuration is invalid.');
}
unset($__caConfigured);

if (!function_exists('festora_db_dsn')) {
    function festora_db_dsn(string $database): string {
        if ($database === '' || strpbrk($database, ";\r\n") !== false) {
            throw new InvalidArgumentException('Database configuration is invalid.');
        }
        return 'mysql:host=' . DB_HOST . ';port=' . (int)DB_PORT
            . ';dbname=' . $database . ';charset=utf8mb4';
    }
}

if (!function_exists('festora_db_ca_bundle')) {
    function festora_db_ca_bundle(): string {
        $configured = defined('DB_SSL_CA') ? trim((string)DB_SSL_CA) : '';
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        } else {
            $certificateFile = getenv('SSL_CERT_FILE');
            if (is_string($certificateFile) && trim($certificateFile) !== '') {
                $candidates[] = trim($certificateFile);
            }
            $iniCertificateFile = ini_get('openssl.cafile');
            if (is_string($iniCertificateFile) && trim($iniCertificateFile) !== '') {
                $candidates[] = trim($iniCertificateFile);
            }
            if (function_exists('openssl_get_cert_locations')) {
                $locations = openssl_get_cert_locations();
                if (is_array($locations) && !empty($locations['default_cert_file'])) {
                    $candidates[] = (string)$locations['default_cert_file'];
                }
            }
            $candidates = array_merge($candidates, [
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/ssl/cert.pem',
                '/etc/pki/tls/certs/ca-bundle.crt',
            ]);
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $resolved = realpath($candidate);
                return $resolved === false ? $candidate : $resolved;
            }
        }

        throw new RuntimeException(
            $configured !== '' ? 'Database TLS configuration is invalid.' : 'Database TLS configuration is unavailable.'
        );
    }
}

if (!function_exists('festora_db_pdo_options')) {
    function festora_db_pdo_options(bool $allowMultipleStatements = true): array {
        if (!extension_loaded('pdo_mysql') || !extension_loaded('mysqlnd')
            || !defined('PDO::MYSQL_ATTR_SSL_CA')
            || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            throw new RuntimeException('PDO MySQL TLS support is unavailable.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            constant('PDO::MYSQL_ATTR_SSL_CA') => festora_db_ca_bundle(),
            constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') => true,
        ];
        if (!$allowMultipleStatements) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }
        return $options;
    }
}

/**
 * True ONLY when the FESTORA_ENV environment variable is exactly "development".
 * Unset, empty, misspelled or any other value means production. The request's Host
 * header, client address or any other request data is never used to decide this.
 * Demo accounts (@festora.test) are usable only when this returns true.
 */
if (!function_exists('festora_is_development')) {
    function festora_is_development(): bool {
        return getenv('FESTORA_ENV') === 'development';
    }
}

// Timezone: Asia/Kolkata by default; override with the FESTORA_TZ environment variable.
$__tz = getenv('FESTORA_TZ') ?: 'Asia/Kolkata';
date_default_timezone_set(in_array($__tz, timezone_identifiers_list(), true) ? $__tz : 'Asia/Kolkata');
unset($__tz);

// Errors stay hidden in production. Debug display is opt-in only for development.
$__debug = festora_is_development() && getenv('FESTORA_DEBUG') === '1';
ini_set('display_errors', $__debug ? '1' : '0');
unset($__debug);

// Base URL path of the app (auto-detected so it works in /festora or at the web root).
$__script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$__script = preg_replace('#/(admin|api)$#', '', $__script);
define('BASE_URL', rtrim($__script, '/'));

define('APP_NAME', 'Festora');
define('CATEGORIES', ['Technical', 'Cultural', 'Sports', 'Workshop', 'Seminar', 'Other']);
