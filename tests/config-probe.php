<?php
if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    exit(2);
}

require $argv[1];

$mode = $argv[2] ?? '';
if ($mode === 'credentials') {
    if (DB_HOST === 'test-host' && DB_NAME === 'test-db' && DB_USER === 'test-user' && DB_PASS === 'test-password') {
        echo 'CONFIG_OK';
        exit(0);
    }
    exit(3);
}

if ($mode === 'production-debug') {
    echo ini_get('display_errors') === '0' && ini_get('display_startup_errors') === '0'
        ? 'PRODUCTION_DEBUG_OFF' : 'PRODUCTION_DEBUG_ON';
    exit(0);
}

if ($mode === 'db-connection-settings') {
    $dsn = festora_db_dsn(DB_NAME);
    $options = festora_db_pdo_options();
    $cliOptions = festora_db_pdo_options(false);
    $caOption = constant('PDO::MYSQL_ATTR_SSL_CA');
    $verifyOption = constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT');
    $expectedPort = getenv('FESTORA_TEST_EXPECTED_DB_PORT');
    $expectedCa = getenv('FESTORA_TEST_EXPECTED_CA');
    $resolvedCa = is_string($expectedCa) ? realpath($expectedCa) : false;
    if ($dsn === 'mysql:host=test-host;port=' . (int)$expectedPort . ';dbname=test-db;charset=utf8mb4'
        && $resolvedCa !== false && ($options[$caOption] ?? null) === $resolvedCa
        && ($options[$verifyOption] ?? false) === true
        && ($cliOptions[$verifyOption] ?? false) === true
        && ($cliOptions[PDO::MYSQL_ATTR_MULTI_STATEMENTS] ?? true) === false) {
        echo 'DB_CONNECTION_SETTINGS_OK';
        exit(0);
    }
    exit(4);
}
