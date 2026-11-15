<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';

const FESTORA_MIGRATION_TABLE = 'festora_schema_migrations';
const FESTORA_APPLICATION_TABLES = [
    'users',
    'events',
    'registrations',
    'schedule_items',
    'announcements',
    'event_admin_assignments',
];

function festora_cli_database_name(array $argv): string {
    $database = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--database=([A-Za-z0-9_]{1,64})$/', $argument, $matches)) {
            if ($database !== null) {
                throw new InvalidArgumentException('Specify --database once.');
            }
            $database = $matches[1];
        } else {
            throw new InvalidArgumentException('Only --database=NAME is supported.');
        }
    }

    if ($database === null) {
        throw new InvalidArgumentException('An explicit --database=NAME is required.');
    }
    if (in_array(strtolower($database), ['information_schema', 'mysql', 'performance_schema', 'sys'], true)) {
        throw new InvalidArgumentException('System databases cannot be migration targets.');
    }

    return $database;
}

function festora_cli_connect(string $database): PDO {
    return new PDO(
        festora_db_dsn($database),
        DB_USER,
        DB_PASS,
        festora_db_pdo_options(false)
    );
}

function festora_migration_files(string $directory): array {
    $paths = glob($directory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    usort($paths, static function (string $left, string $right): int {
        $leftNumber = preg_match('/^([0-9]+)_/', basename($left), $leftVersion) ? (int)$leftVersion[1] : PHP_INT_MAX;
        $rightNumber = preg_match('/^([0-9]+)_/', basename($right), $rightVersion) ? (int)$rightVersion[1] : PHP_INT_MAX;
        $numericOrder = $leftNumber <=> $rightNumber;
        return $numericOrder !== 0 ? $numericOrder : strcmp($left, $right);
    });
    if (!$paths) {
        throw new RuntimeException('No migration files were found.');
    }

    $migrations = [];
    $numericVersions = [];
    foreach ($paths as $path) {
        $filename = basename($path);
        if (!preg_match('/^([0-9]{3,})_[a-z0-9_]+\.sql$/', $filename, $matches)) {
            throw new RuntimeException('Invalid migration filename: ' . $filename);
        }
        $version = $matches[1];
        $numericVersion = (int)$version;
        if ($numericVersion === 0 || sprintf('%03d', $numericVersion) !== $version
            || isset($numericVersions[$numericVersion])) {
            throw new RuntimeException('Migration versions must be positive and unique: ' . $version);
        }
        $numericVersions[$numericVersion] = true;
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum migration: ' . $filename);
        }
        $migrations[$version] = [
            'version' => $version,
            'path' => $path,
            'checksum' => $checksum,
        ];
    }

    $orderedVersions = array_keys($numericVersions);
    sort($orderedVersions, SORT_NUMERIC);
    foreach ($orderedVersions as $index => $version) {
        if ($version !== $index + 1) {
            throw new RuntimeException('Migration versions must form a consecutive sequence starting at 001.');
        }
    }

    return $migrations;
}

function festora_migration_lock_name(string $database): string {
    return 'festora-migrate-' . substr(hash('sha256', strtolower($database)), 0, 48);
}

function festora_acquire_migration_lock(PDO $pdo, string $database): string {
    $name = festora_migration_lock_name($database);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $statement->execute([$name]);
    if ((int)$statement->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the database migration lock.');
    }
    return $name;
}

function festora_release_migration_lock(PDO $pdo, string $name): void {
    $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$name]);
}

function festora_migration_table_exists(PDO $pdo): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = \'BASE TABLE\''
    );
    $statement->execute([FESTORA_MIGRATION_TABLE]);
    return (int)$statement->fetchColumn() === 1;
}

function festora_assert_migration_table(PDO $pdo): void {
    $statement = $pdo->prepare(
        'SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = \'BASE TABLE\''
    );
    $statement->execute([FESTORA_MIGRATION_TABLE]);
    if (strtolower((string)$statement->fetchColumn()) !== 'innodb') {
        throw new RuntimeException('Migration history table is missing or is not InnoDB.');
    }

    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([FESTORA_MIGRATION_TABLE]);
    $actual = [];
    foreach ($statement->fetchAll() as $column) {
        $actual[$column['COLUMN_NAME']] = [
            'type' => strtolower($column['COLUMN_TYPE']),
            'nullable' => $column['IS_NULLABLE'] === 'YES',
        ];
    }
    $expected = [
        'version' => ['type' => 'varchar(64)', 'nullable' => false],
        'checksum' => ['type' => 'char(64)', 'nullable' => false],
        'status' => ['type' => "enum('started','applied','failed')", 'nullable' => false],
        'started_at' => ['type' => 'timestamp', 'nullable' => false],
        'applied_at' => ['type' => 'timestamp', 'nullable' => true],
        'failure_reason' => ['type' => 'varchar(255)', 'nullable' => true],
    ];
    ksort($actual);
    ksort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException('Migration history table has an unexpected structure.');
    }

    $statement = $pdo->prepare(
        'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([FESTORA_MIGRATION_TABLE]);
    $indexes = $statement->fetchAll();
    if (count($indexes) !== 1 || $indexes[0]['INDEX_NAME'] !== 'PRIMARY'
        || (int)$indexes[0]['NON_UNIQUE'] !== 0 || $indexes[0]['COLUMN_NAME'] !== 'version'
        || (int)$indexes[0]['SEQ_IN_INDEX'] !== 1) {
        throw new RuntimeException('Migration history table has an unexpected primary key.');
    }
}

function festora_assert_empty_schema(PDO $pdo): void {
    $checks = [
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()',
        'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()',
        'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()',
    ];
    foreach ($checks as $query) {
        if ((int)$pdo->query($query)->fetchColumn() !== 0) {
            throw new RuntimeException('Target database is not empty; refusing to initialize it.');
        }
    }
}

function festora_create_migration_table(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE `' . FESTORA_MIGRATION_TABLE . '` (
            version VARCHAR(64) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            status ENUM(\'started\',\'applied\',\'failed\') NOT NULL,
            started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_at TIMESTAMP NULL DEFAULT NULL,
            failure_reason VARCHAR(255) NULL
        ) ENGINE=InnoDB'
    );
}

function festora_read_migration_history(PDO $pdo, array $migrations): array {
    if (!festora_migration_table_exists($pdo)) {
        throw new RuntimeException('Migration history table is missing.');
    }
    festora_assert_migration_table($pdo);

    $rows = $pdo->query(
        'SELECT version, checksum, status, applied_at, failure_reason
         FROM `' . FESTORA_MIGRATION_TABLE . '` ORDER BY CAST(version AS UNSIGNED)'
    )->fetchAll();
    $history = [];
    foreach ($rows as $row) {
        $version = (string)$row['version'];
        if (!isset($migrations[$version])) {
            throw new RuntimeException('Database contains a migration version absent from this release.');
        }
        if (!hash_equals($migrations[$version]['checksum'], (string)$row['checksum'])) {
            throw new RuntimeException('A recorded migration checksum does not match its SQL file.');
        }
        if (!in_array($row['status'], ['started', 'applied', 'failed'], true)) {
            throw new RuntimeException('Database contains an invalid migration status.');
        }
        if (($row['status'] === 'applied' && ($row['applied_at'] === null || $row['failure_reason'] !== null))
            || ($row['status'] === 'started' && ($row['applied_at'] !== null || $row['failure_reason'] !== null))
            || ($row['status'] === 'failed' && ($row['applied_at'] !== null || $row['failure_reason'] === null))) {
            throw new RuntimeException('Database contains inconsistent migration status metadata.');
        }
        $history[$version] = $row;
    }

    return $history;
}

function festora_assert_all_migrations_applied(array $migrations, array $history): void {
    if (count($migrations) !== count($history)) {
        throw new RuntimeException('All schema migrations must be applied before demo seeding.');
    }
    foreach ($migrations as $version => $migration) {
        if (!isset($history[$version]) || $history[$version]['status'] !== 'applied') {
            throw new RuntimeException('All schema migrations must be applied before demo seeding.');
        }
    }
}

function festora_assert_application_schema(PDO $pdo): void {
    $expectedColumns = [
        'users' => [
            'id' => ['int unsigned', false],
            'name' => ['varchar(100)', false],
            'email' => ['varchar(150)', false],
            'password_hash' => ['varchar(255)', false],
            'role' => ["enum('student','organizer','event_admin','super_admin')", false],
            'enrollment_no' => ['varchar(30)', true],
            'branch' => ['varchar(60)', true],
            'created_at' => ['timestamp', true],
            'is_active' => ['tinyint(1)', false],
            'created_by' => ['int unsigned', true],
            'disabled_at' => ['timestamp', true],
        ],
        'events' => [
            'id' => ['int unsigned', false],
            'title' => ['varchar(150)', false],
            'description' => ['text', false],
            'category' => ["enum('Technical','Cultural','Sports','Workshop','Seminar','Other')", false],
            'venue' => ['varchar(150)', false],
            'event_date' => ['date', false],
            'start_time' => ['time', false],
            'end_time' => ['time', true],
            'capacity' => ['int unsigned', false],
            'image_url' => ['varchar(300)', true],
            'organizer_id' => ['int unsigned', false],
            'created_at' => ['timestamp', true],
            'updated_at' => ['timestamp', true],
        ],
        'registrations' => [
            'id' => ['int unsigned', false],
            'event_id' => ['int unsigned', false],
            'user_id' => ['int unsigned', false],
            'status' => ["enum('registered','cancelled')", false],
            'registered_at' => ['timestamp', true],
        ],
        'schedule_items' => [
            'id' => ['int unsigned', false],
            'event_id' => ['int unsigned', false],
            'item_time' => ['time', false],
            'title' => ['varchar(150)', false],
        ],
        'announcements' => [
            'id' => ['int unsigned', false],
            'event_id' => ['int unsigned', true],
            'title' => ['varchar(150)', false],
            'message' => ['text', false],
            'created_at' => ['timestamp', true],
        ],
        'event_admin_assignments' => [
            'event_id' => ['int unsigned', false],
            'admin_id' => ['int unsigned', false],
            'assigned_by' => ['int unsigned', true],
            'assigned_at' => ['timestamp', true],
        ],
    ];

    $tableStatement = $pdo->query(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements','event_admin_assignments')
           AND TABLE_TYPE='BASE TABLE'"
    );
    $actualTables = [];
    foreach ($tableStatement->fetchAll() as $table) {
        if (strtolower((string)$table['ENGINE']) !== 'innodb') {
            throw new RuntimeException('An application table does not use InnoDB.');
        }
        $actualTables[] = (string)$table['TABLE_NAME'];
    }
    $expectedTables = array_keys($expectedColumns);
    sort($actualTables);
    sort($expectedTables);
    if ($actualTables !== $expectedTables) {
        throw new RuntimeException('The migrated application tables are incomplete.');
    }

    $columnStatement = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements','event_admin_assignments')
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $actualColumns = [];
    foreach ($columnStatement->fetchAll() as $column) {
        $type = (string)$column['COLUMN_TYPE'];
        if (stripos($type, 'enum(') !== 0) {
            $type = strtolower($type);
        }
        $actualColumns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = [
            $type,
            $column['IS_NULLABLE'] === 'YES',
        ];
    }
    ksort($actualColumns);
    ksort($expectedColumns);
    if ($actualColumns !== $expectedColumns) {
        throw new RuntimeException('The migrated application columns do not match this release.');
    }

    $uniqueStatement = $pdo->query(
        "SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS COLUMNS_LIST
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements','event_admin_assignments')
         GROUP BY TABLE_NAME, INDEX_NAME
         ORDER BY TABLE_NAME, INDEX_NAME"
    );
    $actualUnique = [];
    foreach ($uniqueStatement->fetchAll() as $index) {
        $actualUnique[$index['TABLE_NAME']][] = (string)$index['COLUMNS_LIST'];
    }
    $expectedUnique = [
        'users' => ['email', 'id'],
        'events' => ['id'],
        'registrations' => ['event_id,user_id', 'id'],
        'schedule_items' => ['id'],
        'announcements' => ['id'],
        'event_admin_assignments' => ['event_id,admin_id'],
    ];
    foreach ($expectedUnique as &$columns) {
        sort($columns);
    }
    unset($columns);
    foreach ($actualUnique as &$columns) {
        sort($columns);
    }
    unset($columns);
    ksort($expectedUnique);
    ksort($actualUnique);
    if ($actualUnique !== $expectedUnique) {
        throw new RuntimeException('The migrated application unique indexes do not match this release.');
    }

    $foreignKeyStatement = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE CONSTRAINT_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements','event_admin_assignments')
           AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME, COLUMN_NAME"
    );
    $actualForeignKeys = [];
    foreach ($foreignKeyStatement->fetchAll() as $foreignKey) {
        $actualForeignKeys[] = [
            $foreignKey['TABLE_NAME'], $foreignKey['COLUMN_NAME'],
            $foreignKey['REFERENCED_TABLE_NAME'], $foreignKey['REFERENCED_COLUMN_NAME'],
        ];
    }
    $expectedForeignKeys = [
        ['announcements', 'event_id', 'events', 'id'],
        ['event_admin_assignments', 'admin_id', 'users', 'id'],
        ['event_admin_assignments', 'assigned_by', 'users', 'id'],
        ['event_admin_assignments', 'event_id', 'events', 'id'],
        ['events', 'organizer_id', 'users', 'id'],
        ['registrations', 'event_id', 'events', 'id'],
        ['registrations', 'user_id', 'users', 'id'],
        ['schedule_items', 'event_id', 'events', 'id'],
        ['users', 'created_by', 'users', 'id'],
    ];
    sort($actualForeignKeys);
    sort($expectedForeignKeys);
    if ($actualForeignKeys !== $expectedForeignKeys) {
        throw new RuntimeException('The migrated application foreign keys do not match this release.');
    }

    // Exact delete rule per constraint. fk_event_owner MUST be RESTRICT so that deleting an
    // administrator can never cascade into events and their registrations.
    // (fk_event_org was the original CASCADE constraint; migration 010 replaced it with fk_event_owner.)
    $expectedRules = [
        'announcements.fk_ann_event' => 'CASCADE',
        'event_admin_assignments.fk_assign_admin' => 'CASCADE',
        'event_admin_assignments.fk_assign_by' => 'SET NULL',
        'event_admin_assignments.fk_assign_event' => 'CASCADE',
        'events.fk_event_owner' => 'RESTRICT',
        'registrations.fk_reg_event' => 'CASCADE',
        'registrations.fk_reg_user' => 'CASCADE',
        'schedule_items.fk_sched_event' => 'CASCADE',
        'users.fk_users_created_by' => 'SET NULL',
    ];
    $ruleStatement = $pdo->query(
        "SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('users','events','registrations','schedule_items','announcements','event_admin_assignments')"
    );
    $actualRules = [];
    foreach ($ruleStatement->fetchAll() as $rule) {
        $actualRules[$rule['TABLE_NAME'] . '.' . $rule['CONSTRAINT_NAME']] = strtoupper((string)$rule['DELETE_RULE']);
    }
    ksort($actualRules);
    ksort($expectedRules);
    if ($actualRules !== $expectedRules) {
        throw new RuntimeException('An application foreign key delete rule does not match this release.');
    }
}

function festora_database_error_code(Throwable $error): string {
    return $error instanceof PDOException ? (string)$error->getCode() : 'n/a';
}
