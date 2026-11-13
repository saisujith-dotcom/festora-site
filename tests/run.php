<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$project = dirname(__DIR__);
require $project . '/config.php';
require_once $project . '/scripts/db-common.php';
require_once $project . '/includes/event-operations.php';
require_once $project . '/includes/event-search.php';
require_once $project . '/includes/bootstrap.php';

$testCount = 0;
$exitCode = 0;
$createdDatabases = [];
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'festora-tests-' . bin2hex(random_bytes(8));
$temporaryRootCreated = false;

function test_check(bool $condition, string $name): void {
    global $testCount;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $name);
    }
    $testCount++;
    echo 'PASS ' . $name . PHP_EOL;
}

function test_environment(array $overrides = [], array $remove = []): array {
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ($remove as $name) {
        unset($environment[$name]);
    }
    foreach ($overrides as $name => $value) {
        $environment[$name] = $value;
    }
    return $environment;
}

function test_process(array $command, string $stdin = '', ?array $environment = null): array {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a test subprocess.');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return ['status' => $status, 'stdout' => $stdout === false ? '' : $stdout, 'stderr' => $stderr === false ? '' : $stderr];
}

function test_pdo(string $database): PDO {
    return festora_cli_connect($database);
}

function test_database_exists(PDO $server, string $database): bool {
    $statement = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $statement->execute([$database]);
    return (int)$statement->fetchColumn() === 1;
}

function test_create_database(PDO $server, string $database): void {
    global $createdDatabases;
    if (!preg_match('/^ftest_[a-f0-9]{16}$/', $database) || strcasecmp($database, DB_NAME) === 0) {
        throw new RuntimeException('Unsafe disposable database name.');
    }
    if (test_database_exists($server, $database)) {
        throw new RuntimeException('Disposable database already exists; refusing to reuse it.');
    }
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdDatabases[] = $database;
}

function test_new_database(PDO $server): string {
    $database = 'ftest_' . bin2hex(random_bytes(8));
    test_create_database($server, $database);
    return $database;
}

function test_schema_signature(PDO $pdo): string {
    $tables = $pdo->query(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements')
         ORDER BY TABLE_NAME"
    )->fetchAll();
    $columns = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
                CHARACTER_SET_NAME, COLLATION_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements')
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    )->fetchAll();
    $indexes = $pdo->query(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements')
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    )->fetchAll();
    $foreignKeys = $pdo->query(
        "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
          AND r.TABLE_NAME=k.TABLE_NAME
         WHERE k.CONSTRAINT_SCHEMA=DATABASE()
           AND k.TABLE_NAME IN ('users','events','registrations','schedule_items','announcements')
           AND k.REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
    )->fetchAll();
    return hash('sha256', json_encode([$tables, $columns, $indexes, $foreignKeys], JSON_UNESCAPED_SLASHES));
}

function test_run_migrations(string $database): array {
    return test_process([PHP_BINARY, dirname(__DIR__) . '/scripts/migrate.php', '--database=' . $database]);
}

function test_user(PDO $pdo, string $name, string $email, string $role = 'student'): int {
    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$name, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role]);
    return (int)$pdo->lastInsertId();
}

function test_event(PDO $pdo, int $organizerId, string $title, int $capacity): int {
    $statement = $pdo->prepare(
        'INSERT INTO events
         (title, description, category, venue, event_date, start_time, end_time, capacity, image_url, organizer_id)
         VALUES (?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), ?, ?, ?, NULL, ?)'
    );
    $statement->execute([$title, 'Disposable regression event description.', 'Technical', 'Test Hall', '10:00:00', '11:00:00', $capacity, $organizerId]);
    return (int)$pdo->lastInsertId();
}

function test_worker_batch(string $database, int $eventId, array $jobs, string $barrier, array $environment): array {
    $processes = [];
    $worker = dirname(__DIR__) . '/tests/db-worker.php';
    foreach ($jobs as $job) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = [PHP_BINARY, $worker, $database, $job[0], (string)$eventId, (string)$job[1]];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a concurrency worker.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    if (file_put_contents($barrier, 'go') === false) {
        throw new RuntimeException('Could not start the concurrency test barrier.');
    }

    $results = [];
    foreach ($processes as $workerProcess) {
        $stdout = stream_get_contents($workerProcess['stdout']);
        fclose($workerProcess['stdout']);
        $stderr = stream_get_contents($workerProcess['stderr']);
        fclose($workerProcess['stderr']);
        $status = proc_close($workerProcess['process']);
        if ($status !== 0) {
            throw new RuntimeException('A concurrency worker failed with status ' . $status . '.');
        }
        $results[] = trim((string)$stdout);
    }
    return $results;
}

function test_configuration(string $temporaryRoot): void {
    $configDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'config-probe';
    mkdir($configDirectory, 0700, true);
    $configCopy = $configDirectory . DIRECTORY_SEPARATOR . 'config.php';
    if (!copy(dirname(__DIR__) . '/config.php', $configCopy)) {
        throw new RuntimeException('Could not create an isolated configuration probe.');
    }
    $probe = dirname(__DIR__) . '/tests/config-probe.php';
    $removed = [
        'FESTORA_DB_HOST', 'FESTORA_DB_PORT', 'FESTORA_DB_NAME', 'FESTORA_DB_USER',
        'FESTORA_DB_PASS', 'FESTORA_DB_SSL_CA', 'FESTORA_TEST_EXPECTED_DB_PORT',
        'FESTORA_TEST_EXPECTED_CA', 'FESTORA_ENV', 'FESTORA_DEBUG',
    ];

    $missing = test_process([PHP_BINARY, $probe, $configCopy], '', test_environment([], $removed));
    test_check($missing['status'] === 0 && trim($missing['stdout']) === 'Database configuration is unavailable.',
        'production configuration fails closed without credentials');

    $productionExampleDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'production-config-example';
    mkdir($productionExampleDirectory, 0700, true);
    if (!copy($configCopy, $productionExampleDirectory . DIRECTORY_SEPARATOR . 'config.php')
        || !copy(dirname(__DIR__) . '/deployment/infinityfree-upload/htdocs/_festora-private/infinityfree-config.example.php',
            $productionExampleDirectory . DIRECTORY_SEPARATOR . 'config.local.php')) {
        throw new RuntimeException('Could not create an isolated production example probe.');
    }
    $productionExample = test_process(
        [PHP_BINARY, $probe, $productionExampleDirectory . DIRECTORY_SEPARATOR . 'config.php'],
        '',
        test_environment([], $removed)
    );
    test_check($productionExample['status'] === 0
        && trim($productionExample['stdout']) === 'Database configuration is unavailable.',
        'InfinityFree configuration example fails closed until real credentials are supplied');

    $earlyDisplayDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'early-display-config';
    mkdir($earlyDisplayDirectory, 0700, true);
    if (!copy($configCopy, $earlyDisplayDirectory . DIRECTORY_SEPARATOR . 'config.php')
        || file_put_contents(
            $earlyDisplayDirectory . DIRECTORY_SEPARATOR . 'config.local.php',
            "<?php\necho ini_get('display_errors') === '0' ? 'EARLY_OFF' : 'EARLY_ON';\n"
                . "define('DB_HOST', 'test-host');\ndefine('DB_NAME', 'test-db');\n"
                . "define('DB_USER', 'test-user');\ndefine('DB_PASS', 'test-password');\n"
        ) === false) {
        throw new RuntimeException('Could not create an isolated early-error-display probe.');
    }
    $earlyDisplay = test_process(
        [PHP_BINARY, $probe, $earlyDisplayDirectory . DIRECTORY_SEPARATOR . 'config.php', 'credentials'],
        '',
        test_environment([], $removed)
    );
    test_check($earlyDisplay['status'] === 0 && trim($earlyDisplay['stdout']) === 'EARLY_OFFCONFIG_OK',
        'production error display is disabled before private configuration is loaded');

    $fakeCredentials = [
        'FESTORA_DB_HOST' => 'test-host',
        'FESTORA_DB_NAME' => 'test-db',
        'FESTORA_DB_USER' => 'test-user',
        'FESTORA_DB_PASS' => 'test-password',
    ];
    $caBundle = $configDirectory . DIRECTORY_SEPARATOR . 'test-ca.pem';
    if (file_put_contents($caBundle, 'configuration-only CA path probe') === false) {
        throw new RuntimeException('Could not create an isolated CA-path probe.');
    }
    $defaultPort = test_process(
        [PHP_BINARY, $probe, $configCopy, 'db-connection-settings'],
        '',
        test_environment($fakeCredentials + [
            'FESTORA_DB_SSL_CA' => $caBundle,
            'FESTORA_TEST_EXPECTED_DB_PORT' => '4000',
            'FESTORA_TEST_EXPECTED_CA' => $caBundle,
        ], $removed)
    );
    test_check($defaultPort['status'] === 0 && trim($defaultPort['stdout']) === 'DB_CONNECTION_SETTINGS_OK',
        'database DSN defaults to port 4000 and verifies the configured readable CA bundle');

    $systemCa = test_process(
        [PHP_BINARY, $probe, $configCopy, 'db-connection-settings'],
        '',
        test_environment($fakeCredentials + [
            'SSL_CERT_FILE' => $caBundle,
            'FESTORA_TEST_EXPECTED_DB_PORT' => '4000',
            'FESTORA_TEST_EXPECTED_CA' => $caBundle,
        ], $removed)
    );
    test_check($systemCa['status'] === 0 && trim($systemCa['stdout']) === 'DB_CONNECTION_SETTINGS_OK',
        'system CA bundle is selected when no application CA path is configured');

    $configuredPort = test_process(
        [PHP_BINARY, $probe, $configCopy, 'db-connection-settings'],
        '',
        test_environment(
            $fakeCredentials + [
                'FESTORA_DB_PORT' => '4400',
                'FESTORA_DB_SSL_CA' => $caBundle,
                'FESTORA_TEST_EXPECTED_DB_PORT' => '4400',
                'FESTORA_TEST_EXPECTED_CA' => $caBundle,
            ],
            $removed
        )
    );
    test_check($configuredPort['status'] === 0 && trim($configuredPort['stdout']) === 'DB_CONNECTION_SETTINGS_OK',
        'database DSN honors configured port and CA bundle without connecting');

    $localConfigDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'local-db-port-config';
    mkdir($localConfigDirectory, 0700, true);
    if (!copy($configCopy, $localConfigDirectory . DIRECTORY_SEPARATOR . 'config.php')
        || file_put_contents(
            $localConfigDirectory . DIRECTORY_SEPARATOR . 'config.local.php',
            "<?php\n"
                . "define('DB_HOST', 'test-host');\ndefine('DB_NAME', 'test-db');\n"
                . "define('DB_USER', 'test-user');\ndefine('DB_PASS', 'test-password');\n"
                . "define('DB_PORT', 3307);\n"
                . "define('DB_SSL_CA', " . var_export($caBundle, true) . ");\n"
        ) === false) {
        throw new RuntimeException('Could not create an isolated local-config probe.');
    }
    $localPort = test_process(
        [PHP_BINARY, $probe, $localConfigDirectory . DIRECTORY_SEPARATOR . 'config.php', 'db-connection-settings'],
        '',
        test_environment([
            'FESTORA_TEST_EXPECTED_DB_PORT' => '3307',
            'FESTORA_TEST_EXPECTED_CA' => $caBundle,
        ], $removed)
    );
    test_check($localPort['status'] === 0 && trim($localPort['stdout']) === 'DB_CONNECTION_SETTINGS_OK',
        'private local DB_PORT and DB_SSL_CA constants remain supported');

    $invalidPort = test_process(
        [PHP_BINARY, $probe, $configCopy],
        '',
        test_environment($fakeCredentials + ['FESTORA_DB_PORT' => '70000'], $removed)
    );
    test_check($invalidPort['status'] === 0 && trim($invalidPort['stdout']) === 'Database configuration is invalid.',
        'invalid database port fails with a safe message');

    $invalidCa = test_process(
        [PHP_BINARY, $probe, $configCopy],
        '',
        test_environment(
            $fakeCredentials + ['FESTORA_DB_SSL_CA' => $configDirectory . DIRECTORY_SEPARATOR . 'missing-ca.pem'],
            $removed
        )
    );
    test_check($invalidCa['status'] === 0 && trim($invalidCa['stdout']) === 'Database TLS configuration is invalid.',
        'unreadable CA path fails with a safe message');

    $explicit = test_process(
        [PHP_BINARY, $probe, $configCopy, 'credentials'],
        '',
        test_environment($fakeCredentials, $removed)
    );
    test_check($explicit['status'] === 0 && trim($explicit['stdout']) === 'CONFIG_OK',
        'explicit environment credentials are accepted');

    $productionDebug = test_process(
        [PHP_BINARY, $probe, $configCopy, 'production-debug'],
        '',
        test_environment($fakeCredentials + ['FESTORA_ENV' => 'production', 'FESTORA_DEBUG' => '1'], $removed)
    );
    test_check($productionDebug['status'] === 0 && trim($productionDebug['stdout']) === 'PRODUCTION_DEBUG_OFF',
        'production debug flag cannot display errors');
}

function test_security_probes(string $temporaryRoot): void {
    $probe = dirname(__DIR__) . '/tests/security-probe.php';
    $sessionDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'sessions';
    mkdir($sessionDirectory, 0700, true);

    foreach (['https-direct' => true, 'https-trusted' => true, 'https-untrusted' => false, 'https-forwarded-list' => false] as $mode => $secure) {
        $env = test_environment(
            ['FESTORA_TRUSTED_PROXY_IPS' => '192.0.2.10'],
            []
        );
        $result = test_process([PHP_BINARY, $probe, $mode, $sessionDirectory], '', $env);
        $params = json_decode(trim($result['stdout']), true);
        test_check($result['status'] === 0 && is_array($params)
            && $params['secure'] === $secure && $params['httponly'] === true && $params['samesite'] === 'Lax',
            'session cookie policy ' . $mode);
    }

    $helpers = test_process([PHP_BINARY, $probe, 'helpers', $sessionDirectory]);
    test_check($helpers['status'] === 0 && trim($helpers['stdout']) === 'HELPERS_OK',
        'input validation and HTML escaping helpers');

    $logoutCases = [
        ['logout-get', 'PROBE_STATUS=405', 'PROBE_USER=present'],
        ['logout-missing-csrf', 'PROBE_STATUS=419', 'PROBE_USER=present'],
    ];
    foreach ($logoutCases as [$mode, $status, $user]) {
        $result = test_process([PHP_BINARY, $probe, $mode, $sessionDirectory]);
        test_check(str_contains($result['stdout'], $status) && str_contains($result['stdout'], $user),
            'logout rejects ' . $mode);
    }
    $validLogout = test_process([PHP_BINARY, $probe, 'logout-valid', $sessionDirectory]);
    test_check(str_contains($validLogout['stdout'], 'PROBE_STATUS=302')
        && str_contains($validLogout['stdout'], 'PROBE_USER=absent')
        && str_contains($validLogout['stdout'], 'PROBE_CSRF=absent')
        && str_contains($validLogout['stdout'], 'PROBE_FLASH=present')
        && str_contains($validLogout['stdout'], 'PROBE_ROTATED=yes'),
        'valid CSRF logout clears session and rotates its identifier');

    $studentAdmin = test_process([PHP_BINARY, $probe, 'student-admin', $sessionDirectory]);
    test_check(str_contains($studentAdmin['stdout'], 'PROBE_STATUS=403'),
        'student role is denied organizer-only access');

    $logProbe = test_process([PHP_BINARY, $probe, 'log-sanitize', $sessionDirectory]);
    test_check($logProbe['status'] === 0
        && str_contains($logProbe['stderr'], 'festora registration error=PDOException sqlstate=23000')
        && !str_contains($logProbe['stderr'], 'private-path')
        && !str_contains($logProbe['stderr'], 'password-token')
        && !str_contains($logProbe['stderr'], 'session-secret'),
        'structured logging excludes exception messages and sensitive values');

    $rateLimit = test_process(
        [PHP_BINARY, $probe, 'rate-limit', $sessionDirectory],
        '',
        test_environment(['FESTORA_STATE_DIR' => $temporaryRoot . DIRECTORY_SEPARATOR . 'rate-limit'])
    );
    test_check($rateLimit['status'] === 0 && trim($rateLimit['stdout']) === 'RATE_LIMIT_OK',
        'login rate limiter enforces account and IP attempt limits');

    $documentRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'rate-limit-document-root';
    $documentState = $documentRoot . DIRECTORY_SEPARATOR . '_festora-state';
    mkdir($documentState, 0700, true);
    $accessFile = $documentState . DIRECTORY_SEPARATOR . '.htaccess';
    $statePolicy = file_get_contents(dirname(__DIR__) . '/deployment/infinityfree-upload/htdocs/_festora-state/.htaccess');
    file_put_contents($accessFile, (string)$statePolicy);
    $docrootStateEnvironment = [
        'FESTORA_STATE_DIR' => $documentState,
        'FESTORA_TEST_DOCUMENT_ROOT' => $documentRoot,
        'FESTORA_ALLOW_DOCROOT_STATE' => '1',
    ];
    $notOptedInEnvironment = $docrootStateEnvironment;
    unset($notOptedInEnvironment['FESTORA_ALLOW_DOCROOT_STATE']);
    $notOptedIn = test_process(
        [PHP_BINARY, $probe, 'rate-limit-docroot-state'],
        '',
        test_environment($notOptedInEnvironment, ['FESTORA_ALLOW_DOCROOT_STATE'])
    );
    test_check($notOptedIn['status'] === 0 && trim($notOptedIn['stdout']) === 'STATE_DENIED',
        'document-root state remains forbidden without explicit opt-in');

    unlink($accessFile);
    $unprotected = test_process(
        [PHP_BINARY, $probe, 'rate-limit-docroot-state'],
        '',
        test_environment($docrootStateEnvironment)
    );
    test_check($unprotected['status'] === 0 && trim($unprotected['stdout']) === 'STATE_DENIED',
        'document-root state is forbidden without a deny-all access file');

    file_put_contents($accessFile, (string)$statePolicy);
    $protected = test_process(
        [PHP_BINARY, $probe, 'rate-limit-docroot-state'],
        '',
        test_environment($docrootStateEnvironment)
    );
    test_check($protected['status'] === 0
        && trim($protected['stdout']) === 'STATE=' . realpath($documentState),
        'explicitly enabled state is accepted only at the protected document-root sibling path');

    file_put_contents(
        $accessFile,
        (string)$statePolicy . "\n<IfModule mod_authz_core.c>\n    Require all granted\n</IfModule>\n"
    );
    $permissivePolicy = test_process(
        [PHP_BINARY, $probe, 'rate-limit-docroot-state'],
        '',
        test_environment($docrootStateEnvironment)
    );
    test_check($permissivePolicy['status'] === 0 && trim($permissivePolicy['stdout']) === 'STATE_DENIED',
        'document-root state rejects modified or permissive access-control files');

    $alternateState = $documentRoot . DIRECTORY_SEPARATOR . 'alternate-state';
    mkdir($alternateState, 0700, true);
    file_put_contents($alternateState . DIRECTORY_SEPARATOR . '.htaccess', (string)$statePolicy);
    $alternateEnvironment = $docrootStateEnvironment;
    $alternateEnvironment['FESTORA_STATE_DIR'] = $alternateState;
    $alternatePath = test_process(
        [PHP_BINARY, $probe, 'rate-limit-docroot-state'],
        '',
        test_environment($alternateEnvironment)
    );
    test_check($alternatePath['status'] === 0 && trim($alternatePath['stdout']) === 'STATE_DENIED',
        'document-root state opt-in rejects paths other than the exact protected sibling directory');
}

$stage = 'initializing';
try {
    if (!mkdir($temporaryRoot, 0700, true)) {
        throw new RuntimeException('Could not create temporary test workspace.');
    }
    $temporaryRootCreated = true;
    $stage = 'configuration and session probes';
    test_configuration($temporaryRoot);
    test_security_probes($temporaryRoot);

    $stage = 'read-only database connection';
    $server = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $testDb = test_new_database($server);
    $dumpDb = test_new_database($server);
    $seedDb = test_new_database($server);
    $nonEmptyDb = test_new_database($server);
    $lockDb = test_new_database($server);
    $invalidSchemaDb = test_new_database($server);

    $stage = 'fresh migration';
    $migrated = test_run_migrations($testDb);
    test_check($migrated['status'] === 0 && str_contains($migrated['stdout'], 'Migrations are up to date.'),
        'fresh disposable database migrations');
    $rerun = test_run_migrations($testDb);
    test_check($rerun['status'] === 0 && str_contains($rerun['stdout'], 'Migrations are up to date.'),
        'repeated migration execution is idempotent');

    $stage = 'schema integrity comparison';
    $dump = file_get_contents(dirname(__DIR__) . '/database/festora.sql');
    preg_match_all(
        '~CREATE TABLE\s+(users|events|registrations|schedule_items|announcements)\s*\(.*?\)\s*ENGINE=InnoDB;~is',
        (string)$dump,
        $ddl
    );
    if (count($ddl[0]) !== 5) {
        throw new RuntimeException('Could not isolate all legacy schema DDL for comparison.');
    }
    $dumpPdo = test_pdo($dumpDb);
    foreach ($ddl[0] as $statement) {
        $dumpPdo->exec($statement);
    }
    $test_check_tables = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (?, ?, ?, ?, ?)'
    );
    $test_check_tables->execute([$dumpDb, 'users', 'events', 'registrations', 'schedule_items', 'announcements']);
    test_check((int)$test_check_tables->fetchColumn() === 5
        && test_schema_signature(test_pdo($testDb)) === test_schema_signature($dumpPdo),
        'migration schema matches festora.sql tables, columns, indexes, foreign keys, engine and collation');
    festora_assert_application_schema(test_pdo($testDb));
    test_check(true, 'schema validator accepts the intact migrated application schema');

    $stage = 'migration safety checks';
    $noTarget = test_process([PHP_BINARY, dirname(__DIR__) . '/scripts/migrate.php']);
    test_check($noTarget['status'] !== 0, 'migration target must be explicit');
    $systemTarget = test_process([PHP_BINARY, dirname(__DIR__) . '/scripts/migrate.php', '--database=mysql']);
    test_check($systemTarget['status'] !== 0, 'system database migration target is rejected');

    $nonEmptyPdo = test_pdo($nonEmptyDb);
    $nonEmptyPdo->exec('CREATE TABLE marker (id INT PRIMARY KEY)');
    $nonEmpty = test_run_migrations($nonEmptyDb);
    test_check($nonEmpty['status'] !== 0, 'non-empty untracked database is refused');

    $stage = 'migration advisory-lock behavior';
    $migrationCommands = [];
    for ($i = 0; $i < 2; $i++) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/scripts/migrate.php', '--database=' . $lockDb],
            $descriptors,
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start concurrent migration command.');
        }
        fclose($pipes[0]);
        $migrationCommands[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    $lockResults = [];
    foreach ($migrationCommands as $command) {
        $stdout = stream_get_contents($command['stdout']);
        fclose($command['stdout']);
        $stderr = stream_get_contents($command['stderr']);
        fclose($command['stderr']);
        $status = proc_close($command['process']);
        $lockResults[] = ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }
    test_check($lockResults[0]['status'] === 0 && $lockResults[1]['status'] === 0
        && test_schema_signature(test_pdo($lockDb)) === test_schema_signature(test_pdo($testDb)),
        'concurrent migration runners serialize through the advisory lock');
    $lockReleased = test_run_migrations($lockDb);
    test_check($lockReleased['status'] === 0, 'migration advisory lock is released for a later run');

    $migrationDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'migration-fixtures';
    mkdir($migrationDirectory, 0700, true);
    file_put_contents($migrationDirectory . DIRECTORY_SEPARATOR . '001_first.sql', 'CREATE TABLE first_test (id INT);');
    file_put_contents($migrationDirectory . DIRECTORY_SEPARATOR . '003_gap.sql', 'CREATE TABLE gap_test (id INT);');
    $gapRejected = false;
    try {
        festora_migration_files($migrationDirectory);
    } catch (RuntimeException $error) {
        $gapRejected = true;
    }
    test_check($gapRejected, 'missing migration sequence number is rejected');
    unlink($migrationDirectory . DIRECTORY_SEPARATOR . '003_gap.sql');
    file_put_contents($migrationDirectory . DIRECTORY_SEPARATOR . '001_second.sql', 'CREATE TABLE second_test (id INT);');
    $duplicateRejected = false;
    try {
        festora_migration_files($migrationDirectory);
    } catch (RuntimeException $error) {
        $duplicateRejected = true;
    }
    test_check($duplicateRejected, 'duplicate migration version is rejected');

    $migrationPdo = test_pdo($testDb);
    $migrationFiles = festora_migration_files(dirname(__DIR__) . '/database/migrations');
    $knownChecksum = $migrationFiles['001']['checksum'];
    $migrationPdo->prepare('UPDATE festora_schema_migrations SET checksum=? WHERE version=?')
        ->execute([str_repeat('0', 64), '001']);
    $drift = test_run_migrations($testDb);
    test_check($drift['status'] !== 0, 'migration checksum drift is rejected');
    $migrationPdo->prepare('UPDATE festora_schema_migrations SET checksum=? WHERE version=?')
        ->execute([$knownChecksum, '001']);

    $secondMigration = $migrationPdo->query(
        "SELECT checksum, status, started_at, applied_at, failure_reason FROM festora_schema_migrations WHERE version='002'"
    )->fetch();
    $migrationPdo->exec("DELETE FROM festora_schema_migrations WHERE version='002'");
    $outOfOrder = test_run_migrations($testDb);
    test_check($outOfOrder['status'] !== 0, 'out-of-order applied migration history is rejected');
    $restore = $migrationPdo->prepare(
        'INSERT INTO festora_schema_migrations (version, checksum, status, started_at, applied_at, failure_reason)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $restore->execute(['002', $secondMigration['checksum'], $secondMigration['status'], $secondMigration['started_at'],
        $secondMigration['applied_at'], $secondMigration['failure_reason']]);

    $migrationPdo->prepare(
        "INSERT INTO festora_schema_migrations (version, checksum, status, failure_reason)
         VALUES ('999', ?, 'failed', 'test-only unknown version')"
    )->execute([str_repeat('0', 64)]);
    $unknownVersion = test_run_migrations($testDb);
    test_check($unknownVersion['status'] !== 0, 'unknown migration version in the ledger is rejected');
    $migrationPdo->exec("DELETE FROM festora_schema_migrations WHERE version='999'");

    $lastMigration = $migrationPdo->query(
        "SELECT status, applied_at, failure_reason FROM festora_schema_migrations WHERE version='005'"
    )->fetch();
    $migrationPdo->exec(
        "UPDATE festora_schema_migrations
         SET status='failed', applied_at=NULL, failure_reason='simulated test failure'
         WHERE version='005'"
    );
    $failedMigration = test_run_migrations($testDb);
    test_check($failedMigration['status'] !== 0, 'failed migration state blocks automatic replay');

    $migrationPdo->exec(
        "UPDATE festora_schema_migrations SET status='started', applied_at=NULL, failure_reason=NULL WHERE version='005'"
    );
    $interrupted = test_run_migrations($testDb);
    test_check($interrupted['status'] !== 0, 'incomplete migration state blocks automatic replay');

    $stage = 'registration/capacity transactions';
    $restoreLast = $migrationPdo->prepare(
        'UPDATE festora_schema_migrations SET status=?, applied_at=?, failure_reason=? WHERE version=?'
    );
    $restoreLast->execute([$lastMigration['status'], $lastMigration['applied_at'], $lastMigration['failure_reason'], '005']);
    $organizerId = test_user($migrationPdo, 'Test Organizer', 'organizer@regression.invalid', 'organizer');
    $students = [];
    for ($i = 1; $i <= 12; $i++) {
        $students[] = test_user($migrationPdo, 'Student ' . $i, 'student' . $i . '@regression.invalid');
    }
    $eventId = test_event($migrationPdo, $organizerId, 'Capacity regression event', 2);

    $registered = register_for_event($migrationPdo, $eventId, $students[0], 'register', date('Y-m-d'));
    $duplicate = register_for_event($migrationPdo, $eventId, $students[0], 'register', date('Y-m-d'));
    $second = register_for_event($migrationPdo, $eventId, $students[1], 'register', date('Y-m-d'));
    $full = register_for_event($migrationPdo, $eventId, $students[2], 'register', date('Y-m-d'));
    test_check($registered['status'] === 'confirmed' && $duplicate['status'] === 'duplicate'
        && $second['status'] === 'confirmed' && $full['status'] === 'full',
        'registration, duplicate prevention and capacity boundary');

    $update = [
        'title' => 'Capacity regression event',
        'description' => 'Disposable regression event description.',
        'category' => 'Technical',
        'venue' => 'Test Hall',
        'event_date' => date('Y-m-d', strtotime('+1 day')),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'capacity' => 1,
        'image_url' => null,
    ];
    test_check(!update_event_with_capacity($migrationPdo, $eventId, $update),
        'capacity cannot be reduced below active registration count');
    $capacity = $migrationPdo->prepare('SELECT capacity FROM events WHERE id=?');
    $capacity->execute([$eventId]);
    test_check((int)$capacity->fetchColumn() === 2, 'rejected capacity update rolls back without changing registrations');

    register_for_event($migrationPdo, $eventId, $students[0], 'cancel', date('Y-m-d'));
    $update['capacity'] = 1;
    test_check(update_event_with_capacity($migrationPdo, $eventId, $update),
        'capacity may be reduced to the active registration count');
    $reRegistration = register_for_event($migrationPdo, $eventId, $students[0], 'register', date('Y-m-d'));
    test_check($reRegistration['status'] === 'full', 'cancelled unique registration remains preserved');

    $payload = '<script>alert(1)</script>';
    $update['title'] = $payload;
    test_check(update_event_with_capacity($migrationPdo, $eventId, $update)
        && e($payload) === '&lt;script&gt;alert(1)&lt;/script&gt;',
        'prepared event update and HTML output escaping handle XSS-shaped input');
    test_check(search_events($migrationPdo, "' OR 1=1 --", '', true) === [],
        'event search treats SQL injection payload as data');

    $stage = 'registration concurrency race';
    $raceEvent = test_event($migrationPdo, $organizerId, 'Concurrent capacity event', 4);
    $barrier = $temporaryRoot . DIRECTORY_SEPARATOR . 'workers.ready';
    $jobs = [];
    for ($i = 2; $i < 10; $i++) {
        $jobs[] = ['register', $students[$i]];
    }
    $jobs[] = ['capacity', 2];
    $workerEnv = test_environment(['FESTORA_TEST_BARRIER' => $barrier]);
    $workerResults = test_worker_batch($testDb, $raceEvent, $jobs, $barrier, $workerEnv);
    $activeQuery = $migrationPdo->prepare(
        "SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='registered'"
    );
    $activeQuery->execute([$raceEvent]);
    $activeCount = (int)$activeQuery->fetchColumn();
    $capacity->execute([$raceEvent]);
    $raceCapacity = (int)$capacity->fetchColumn();
    test_check(count($workerResults) === count($jobs) && $activeCount <= $raceCapacity,
        'concurrent registrations and admin capacity reduction never exceed capacity');
    $initialPreserved = $migrationPdo->prepare(
        'SELECT COUNT(*) FROM registrations WHERE event_id=? AND user_id IN (?,?)'
    );
    $initialPreserved->execute([$eventId, $students[0], $students[1]]);
    test_check((int)$initialPreserved->fetchColumn() === 2,
        'capacity changes preserve existing registration rows');

    $stage = 'demo seeding and admin provisioning';
    $unmigratedSeed = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/seed-demo.php', '--database=' . $seedDb],
        '',
        test_environment(['FESTORA_ENV' => 'development'])
    );
    test_check($unmigratedSeed['status'] !== 0, 'demo seeding refuses an unmigrated database');
    $seedMigration = test_run_migrations($seedDb);
    test_check($seedMigration['status'] === 0, 'demo fixture schema migration');
    festora_assert_application_schema(test_pdo($seedDb));
    $productionSeed = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/seed-demo.php', '--database=' . $seedDb],
        '',
        test_environment(['FESTORA_ENV' => 'production'])
    );
    test_check($productionSeed['status'] !== 0, 'demo seeding is denied in production mode');
    $noSeedTarget = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/seed-demo.php'],
        '',
        test_environment(['FESTORA_ENV' => 'development'])
    );
    test_check($noSeedTarget['status'] !== 0, 'demo seeding requires an explicit database target');
    $seeded = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/seed-demo.php', '--database=' . $seedDb],
        '',
        test_environment(['FESTORA_ENV' => 'development'])
    );
    test_check($seeded['status'] === 0 && str_contains($seeded['stdout'], 'Demo data inserted.'),
        'demo seed works on an empty migrated disposable database');

    $seedPdo = test_pdo($seedDb);
    $countsBefore = [];
    foreach (FESTORA_APPLICATION_TABLES as $table) {
        $countsBefore[$table] = (int)$seedPdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    $seedAgain = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/seed-demo.php', '--database=' . $seedDb],
        '',
        test_environment(['FESTORA_ENV' => 'development'])
    );
    $countsAfter = [];
    foreach (FESTORA_APPLICATION_TABLES as $table) {
        $countsAfter[$table] = (int)$seedPdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    test_check($seedAgain['status'] !== 0 && $countsBefore === $countsAfter,
        'repeated demo seeding is refused without changing existing disposable data');

    $invalidSchemaMigration = test_run_migrations($invalidSchemaDb);
    test_check($invalidSchemaMigration['status'] === 0, 'invalid-schema fixture migration');
    test_pdo($invalidSchemaDb)->exec('ALTER TABLE events MODIFY capacity INT NOT NULL DEFAULT 50');
    $invalidSchemaSeed = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/seed-demo.php', '--database=' . $invalidSchemaDb],
        '',
        test_environment(['FESTORA_ENV' => 'development'])
    );
    test_check($invalidSchemaSeed['status'] !== 0,
        'demo seeding refuses a migrated database with schema drift');

    $adminEmail = 'provisioned-admin@regression.invalid';
    $adminPassword = bin2hex(random_bytes(16)) . 'Aa1!';
    $provision = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/provision-admin.php', '--database=' . $seedDb,
            '--email=' . $adminEmail, '--name=Provisioned Admin', '--password-stdin'],
        $adminPassword . PHP_EOL
    );
    $adminQuery = $seedPdo->prepare('SELECT role, password_hash FROM users WHERE email=?');
    $adminQuery->execute([$adminEmail]);
    $adminRow = $adminQuery->fetch();
    test_check($provision['status'] === 0 && $adminRow
        && $adminRow['role'] === 'organizer' && password_verify($adminPassword, $adminRow['password_hash']),
        'CLI provisioning creates a password-hashed organizer account');
    $provisionAgain = test_process(
        [PHP_BINARY, dirname(__DIR__) . '/scripts/provision-admin.php', '--database=' . $seedDb,
            '--email=' . $adminEmail, '--name=Provisioned Admin', '--password-stdin'],
        $adminPassword . PHP_EOL
    );
    test_check($provisionAgain['status'] !== 0, 'administrator provisioning refuses duplicate accounts');

    $secondStudentEmail = 'second-student@regression.invalid';
    $secondStudentPassword = bin2hex(random_bytes(16)) . 'Bb2!';
    $secondStudentInsert = $seedPdo->prepare(
        "INSERT INTO users (name, email, password_hash, role)
         VALUES (?, ?, ?, 'student')"
    );
    $secondStudentInsert->execute([
        'Second HTTP Student',
        $secondStudentEmail,
        password_hash($secondStudentPassword, PASSWORD_DEFAULT),
    ]);

    $stage = 'isolated HTTP functional/security flows';
    $webInput = json_encode([
        'database' => $seedDb,
        'temporary_root' => $temporaryRoot,
        'admin_email' => $adminEmail,
        'admin_password' => $adminPassword,
        'second_student_email' => $secondStudentEmail,
        'second_student_password' => $secondStudentPassword,
    ]);
    $web = test_process([PHP_BINARY, dirname(__DIR__) . '/tests/web-test.php'], $webInput . PHP_EOL);
    if ($web['status'] !== 0) {
        fwrite(STDERR, $web['stdout']);
        fwrite(STDERR, $web['stderr']);
        throw new RuntimeException('Isolated HTTP functional/security tests failed.');
    }
    echo $web['stdout'];

    $stage = 'public document-root and access-control checks';
    $router = file_get_contents(dirname(__DIR__) . '/router.php');
    $ignore = file_get_contents(dirname(__DIR__) . '/.gitignore');
    $projectRoot = dirname(__DIR__);
    $publicRoot = $projectRoot . DIRECTORY_SEPARATOR . 'public';
    $expectedPublicFiles = [
        '.htaccess',
        'admin/announcements.php',
        'admin/event_delete.php',
        'admin/event_form.php',
        'admin/index.php',
        'admin/participants.php',
        'admin/schedule.php',
        'api/events.php',
        'assets/css/style.css',
        'assets/img/events/cultural.svg',
        'assets/img/events/other.svg',
        'assets/img/events/seminar.svg',
        'assets/img/events/sports.svg',
        'assets/img/events/technical.svg',
        'assets/img/events/workshop.svg',
        'assets/js/app.js',
        'event.php',
        'events.php',
        'index.php',
        'login.php',
        'logout.php',
        'my-registrations.php',
        'register.php',
        'signup.php',
    ];
    $actualPublicFiles = [];
    $publicIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($publicIterator as $file) {
        if ($file->isFile()) {
            $actualPublicFiles[] = str_replace(
                '\\',
                '/',
                substr($file->getPathname(), strlen($publicRoot) + 1)
            );
        }
    }
    sort($expectedPublicFiles, SORT_STRING);
    sort($actualPublicFiles, SORT_STRING);
    test_check($actualPublicFiles === $expectedPublicFiles,
        'public document root contains only approved route shims and static assets');

    $rootHtaccess = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.htaccess');
    $publicHtaccess = file_get_contents($publicRoot . DIRECTORY_SEPARATOR . '.htaccess');
    $deployment = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'docs'
        . DIRECTORY_SEPARATOR . 'deployment.md');
    test_check(str_contains((string)$rootHtaccess, 'Require all denied')
        && str_contains((string)$publicHtaccess, 'RewriteRule \\.(?:php')
        && str_contains((string)$deployment, 'DocumentRoot')
        && str_contains((string)$deployment, 'location ~* /(?:uploads|private-storage)')
        && str_contains((string)$deployment, 'location ~ ^/(?:index|events|event|login'),
        'Apache and Nginx deny private paths and unapproved uploaded scripts');

    test_check(str_contains((string)$router, "realpath(__DIR__ . '/public')")
        && str_contains((string)$router, "'/api/events.php'")
        && str_contains((string)$router, "'private-storage'")
        && str_contains((string)$router, "'uploads'")
        && str_contains((string)$ignore, 'config.local.php')
        && str_contains((string)$ignore, 'config.*.local.php')
        && str_contains((string)$ignore, 'private-storage/')
        && str_contains((string)$ignore, '*.key'),
        'development router requires public root and keeps private storage outside it');

    $stagedRoot = $projectRoot . DIRECTORY_SEPARATOR . 'deployment' . DIRECTORY_SEPARATOR
        . 'infinityfree-upload' . DIRECTORY_SEPARATOR . 'htdocs';
    $expectedStagedFiles = [
        '.htaccess',
        '_festora-private/.htaccess',
        '_festora-private/admin/announcements.php',
        '_festora-private/admin/event_delete.php',
        '_festora-private/admin/event_form.php',
        '_festora-private/admin/index.php',
        '_festora-private/admin/participants.php',
        '_festora-private/admin/schedule.php',
        '_festora-private/api/events.php',
        '_festora-private/config.php',
        '_festora-private/event.php',
        '_festora-private/events.php',
        '_festora-private/includes/.htaccess',
        '_festora-private/includes/bootstrap.php',
        '_festora-private/includes/event-operations.php',
        '_festora-private/includes/event-search.php',
        '_festora-private/includes/event_card.php',
        '_festora-private/includes/footer.php',
        '_festora-private/includes/header.php',
        '_festora-private/includes/ratelimit.php',
        '_festora-private/index.php',
        '_festora-private/infinityfree-config.example.php',
        '_festora-private/login.php',
        '_festora-private/logout.php',
        '_festora-private/my-registrations.php',
        '_festora-private/register.php',
        '_festora-private/signup.php',
        '_festora-state/.htaccess',
        'admin/announcements.php',
        'admin/event_delete.php',
        'admin/event_form.php',
        'admin/index.php',
        'admin/participants.php',
        'admin/schedule.php',
        'api/events.php',
        'assets/css/style.css',
        'assets/img/events/cultural.svg',
        'assets/img/events/other.svg',
        'assets/img/events/seminar.svg',
        'assets/img/events/sports.svg',
        'assets/img/events/technical.svg',
        'assets/img/events/workshop.svg',
        'assets/js/app.js',
        'event.php',
        'events.php',
        'index.php',
        'login.php',
        'logout.php',
        'my-registrations.php',
        'register.php',
        'signup.php',
    ];
    $actualStagedFiles = [];
    if (is_dir($stagedRoot)) {
        $stagedIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagedRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($stagedIterator as $file) {
            if ($file->isFile()) {
                $actualStagedFiles[] = str_replace(
                    '\\',
                    '/',
                    substr($file->getPathname(), strlen($stagedRoot) + 1)
                );
            }
        }
    }
    sort($expectedStagedFiles, SORT_STRING);
    sort($actualStagedFiles, SORT_STRING);
    test_check($actualStagedFiles === $expectedStagedFiles,
        'InfinityFree upload package contains only the explicit public/private runtime allowlist');

    $stagedRuntimeCopiesMatch = true;
    foreach ($expectedStagedFiles as $relative) {
        if (str_starts_with($relative, '_festora-private/')
            && !in_array($relative, [
                '_festora-private/.htaccess',
                '_festora-private/infinityfree-config.example.php',
            ], true)) {
            $sourceRelative = substr($relative, strlen('_festora-private/'));
            $sourcePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelative);
            $stagedPath = $stagedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $sourceHash = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : false;
            $stagedHash = is_file($stagedPath) ? hash_file('sha256', $stagedPath) : false;
            $stagedRuntimeCopiesMatch = $stagedRuntimeCopiesMatch
                && is_string($sourceHash) && is_string($stagedHash) && hash_equals($sourceHash, $stagedHash);
        } elseif (str_starts_with($relative, 'assets/')) {
            $sourcePath = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $stagedPath = $stagedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $sourceHash = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : false;
            $stagedHash = is_file($stagedPath) ? hash_file('sha256', $stagedPath) : false;
            $stagedRuntimeCopiesMatch = $stagedRuntimeCopiesMatch
                && is_string($sourceHash) && is_string($stagedHash) && hash_equals($sourceHash, $stagedHash);
        }
    }
    test_check($stagedRuntimeCopiesMatch,
        'staged private controllers, includes, configuration loader, and assets match source files');

    $stagedRootRules = file_get_contents($stagedRoot . DIRECTORY_SEPARATOR . '.htaccess');
    $stagedPrivateRules = file_get_contents($stagedRoot . DIRECTORY_SEPARATOR . '_festora-private'
        . DIRECTORY_SEPARATOR . '.htaccess');
    $stagedStateRules = file_get_contents($stagedRoot . DIRECTORY_SEPARATOR . '_festora-state'
        . DIRECTORY_SEPARATOR . '.htaccess');
    $stagedConfigExample = file_get_contents($stagedRoot . DIRECTORY_SEPARATOR . '_festora-private'
        . DIRECTORY_SEPARATOR . 'infinityfree-config.example.php');
    $wrapperMappings = true;
    foreach ($expectedPublicFiles as $relative) {
        if (!str_ends_with($relative, '.php')) continue;
        $target = str_starts_with($relative, 'admin/') || str_starts_with($relative, 'api/')
            ? "require dirname(__DIR__) . '/_festora-private/" . $relative . "';"
            : "require __DIR__ . '/_festora-private/" . $relative . "';";
        $wrapperPath = $stagedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $wrapperContent = str_replace(["\r\n", "\r"], "\n", trim((string)file_get_contents($wrapperPath)));
        $wrapperMappings = $wrapperMappings && $wrapperContent === "<?php\n" . $target;
    }
    test_check($wrapperMappings
        && str_contains((string)$stagedRootRules, 'uploads|private-storage|_festora-private|_festora-state')
        && str_contains((string)$stagedPrivateRules, 'Require all denied')
        && str_contains((string)$stagedStateRules, 'Require all denied')
        && str_contains((string)$stagedConfigExample, "define('DB_HOST', '');")
        && str_contains((string)$stagedConfigExample, "define('DB_PASS', '');"),
        'staged wrappers map correctly and private paths/configuration fail closed');

    echo 'TOTAL PASS=' . $testCount . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL stage=' . $stage . ' type=' . get_class($error) . ' message=' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (isset($server) && $server instanceof PDO) {
        foreach (array_reverse($createdDatabases) as $database) {
            try {
                if (test_database_exists($server, $database) && strcasecmp($database, DB_NAME) !== 0) {
                    $server->exec('DROP DATABASE `' . $database . '`');
                }
            } catch (Throwable $cleanupError) {
                fwrite(STDERR, 'TEST CLEANUP FAILED for a disposable database (' . get_class($cleanupError) . ').' . PHP_EOL);
                $exitCode = 1;
            }
        }
    }
    if ($temporaryRootCreated && is_dir($temporaryRoot)) {
        try {
            $temporaryBase = realpath(sys_get_temp_dir());
            $temporaryPath = realpath($temporaryRoot);
            $temporaryName = basename($temporaryRoot);
            if ($temporaryBase === false || $temporaryPath === false
                || dirname($temporaryPath) !== $temporaryBase
                || basename($temporaryPath) !== $temporaryName
                || !preg_match('/^festora-tests-[a-f0-9]{16}$/', $temporaryName)
                || is_link($temporaryRoot)) {
                throw new RuntimeException('Refusing to remove an unsafe temporary workspace.');
            }

            $cleanupFailed = false;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($temporaryPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                if (is_link($path)) {
                    $removed = @unlink($path);
                } elseif ($item->isDir()) {
                    $removed = @rmdir($path);
                } else {
                    $removed = @unlink($path);
                }
                if (!$removed && (file_exists($path) || is_link($path))) {
                    fwrite(STDERR, 'TEST CLEANUP FAILED for a temporary item.' . PHP_EOL);
                    $cleanupFailed = true;
                }
            }
            if (!$cleanupFailed && !@rmdir($temporaryPath)) {
                fwrite(STDERR, 'TEST CLEANUP FAILED for the temporary workspace.' . PHP_EOL);
                $cleanupFailed = true;
            }
            if ($cleanupFailed) {
                $exitCode = 1;
            }
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'TEST CLEANUP FAILED for the temporary workspace ('
                . get_class($cleanupError) . ').' . PHP_EOL);
            $exitCode = 1;
        }
    }
}

exit($exitCode);
