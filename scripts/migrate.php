<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db-common.php';

$pdo = null;
$lockName = null;
$exitCode = 0;

try {
    $database = festora_cli_database_name($argv);
    $pdo = festora_cli_connect($database);
    $lockName = festora_acquire_migration_lock($pdo, $database);
    $migrations = festora_migration_files(__DIR__ . '/../database/migrations');

    if (!festora_migration_table_exists($pdo)) {
        festora_assert_empty_schema($pdo);
        festora_create_migration_table($pdo);
    }
    $history = festora_read_migration_history($pdo, $migrations);

    $pendingStarted = false;
    foreach ($migrations as $version => $migration) {
        if (!isset($history[$version])) {
            $pendingStarted = true;
            continue;
        }
        if ($history[$version]['status'] !== 'applied') {
            throw new RuntimeException('A previous migration is incomplete; manual recovery is required.');
        }
        if ($pendingStarted) {
            throw new RuntimeException('Migration history is not a continuous applied prefix.');
        }
    }

    foreach ($migrations as $version => $migration) {
        if (isset($history[$version])) {
            continue;
        }
        $statement = $pdo->prepare(
            'INSERT INTO `' . FESTORA_MIGRATION_TABLE . '` (version, checksum, status)
             VALUES (?, ?, \'started\')'
        );
        $statement->execute([$version, $migration['checksum']]);

        try {
            $sql = file_get_contents($migration['path']);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Migration SQL is empty or unreadable.');
            }
            $statements = array_filter(array_map('trim', explode(';', $sql)));

            foreach ($statements as $statementSql) {
                $pdo->exec($statementSql);
            }
            $statement = $pdo->prepare(
                'UPDATE `' . FESTORA_MIGRATION_TABLE . '`
                 SET status = \'applied\', applied_at = CURRENT_TIMESTAMP, failure_reason = NULL
                 WHERE version = ? AND status = \'started\''
            );
            $statement->execute([$version]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Could not record the migration as applied.');
            }
            echo 'Applied migration ' . $version . PHP_EOL;
        } catch (Throwable $error) {
            try {
                $statement = $pdo->prepare(
                    'UPDATE `' . FESTORA_MIGRATION_TABLE . '`
                     SET status = \'failed\', failure_reason = ?
                     WHERE version = ? AND status = \'started\''
                );
                $statement->execute(['Migration execution failed; inspect the database before recovery.', $version]);
            } catch (Throwable $trackingError) {
                // A failed connection may prevent recording failure; the started status still blocks retries.
            }
            throw new RuntimeException(
                'Migration ' . $version . ' failed. MySQL DDL may have committed partially; inspect and recover manually.',
                0,
                $error
            );
        }
    }

    echo 'Migrations are up to date.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(
        STDERR,
        'Migration command failed (' . get_class($error) . ', code ' . festora_database_error_code($error) . '): '
        . $error->getMessage() . PHP_EOL
    );
    $exitCode = 1;
} finally {
    if ($pdo instanceof PDO && $lockName !== null) {
        try {
            festora_release_migration_lock($pdo, $lockName);
        } catch (Throwable $error) {
            fwrite(STDERR, 'Could not release the database migration lock cleanly.' . PHP_EOL);
            $exitCode = 1;
        }
    }
}

exit($exitCode);
