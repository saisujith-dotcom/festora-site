<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db-common.php';
require_once __DIR__ . '/../includes/account-validation.php';

function festora_admin_arguments(array $argv): array {
    $values = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (in_array($argument, ['--password-stdin', '--super-admin'], true)) {
            $key = $argument === '--password-stdin' ? 'password_stdin' : 'super_admin';
            if (array_key_exists($key, $values)) {
                throw new InvalidArgumentException('Specify each flag once.');
            }
            $values[$key] = true;
            continue;
        }
        if (!preg_match('/^--(database|email|name)=(.*)$/s', $argument, $matches)) {
            throw new InvalidArgumentException(
                'Only --database, --email, --name, --password-stdin and --super-admin are supported.'
            );
        }
        if (isset($values[$matches[1]])) {
            throw new InvalidArgumentException('Each option must be specified once.');
        }
        $values[$matches[1]] = $matches[2];
    }

    foreach (['database', 'email', 'name', 'password_stdin'] as $required) {
        if (!array_key_exists($required, $values)) {
            throw new InvalidArgumentException('All required options must be provided.');
        }
    }
    $values['super_admin'] = $values['super_admin'] ?? false;
    $values['database'] = festora_cli_database_name(['provision-admin.php', '--database=' . $values['database']]);
    $values['email'] = festora_normalize_email($values['email']);
    $values['name'] = trim($values['name']);

    $errors = festora_account_field_errors($values['name'], $values['email']);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }
    if (!festora_is_development() && is_demo_email($values['email'])) {
        throw new InvalidArgumentException('This email domain is reserved. Use a real email address.');
    }

    return $values;
}

$pdo = null;
$lockName = null;
$exitCode = 0;
try {
    $options = festora_admin_arguments($argv);
    $password = fgets(STDIN);
    if (!is_string($password)) {
        throw new RuntimeException('Password input is required on standard input.');
    }
    $password = rtrim($password, "\r\n");
    $passwordErrors = festora_password_policy_errors($password, $options['email']);
    if ($passwordErrors) {
        throw new InvalidArgumentException(implode(' ', $passwordErrors));
    }

    $pdo = festora_cli_connect($options['database']);
    $migrations = festora_migration_files(__DIR__ . '/../database/migrations');
    $history = festora_read_migration_history($pdo, $migrations);
    festora_assert_all_migrations_applied($migrations, $history);
    festora_assert_application_schema($pdo);

    if ($options['super_admin']) {
        $lockName = 'festora-first-admin-' . substr(hash('sha256', strtolower($options['database'])), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            $lockName = null;
            throw new RuntimeException('Could not acquire the initial administrator provisioning lock.');
        }
    }

    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1 FOR UPDATE');
    $statement->execute([$options['email']]);
    if ($statement->fetchColumn()) {
        throw new RuntimeException('An account with that email already exists; no account was changed.');
    }

    if ($options['super_admin']) {
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn();
        if ($existing > 0) {
            throw new RuntimeException(
                'A Super Admin account already exists; initial provisioning is allowed only once.'
            );
        }
    }

    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role)
         VALUES (?, ?, ?, ?)"
    );
    $statement->execute([
        $options['name'],
        $options['email'],
        password_hash($password, PASSWORD_DEFAULT),
        $options['super_admin'] ? 'super_admin' : 'event_admin',
    ]);
    $pdo->commit();
    echo ($options['super_admin'] ? 'Super Admin' : 'Event Admin') . ' account created.' . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($error instanceof PDOException) {
        fwrite(STDERR, 'Administrator provisioning failed because of a database error ('
            . preg_replace('/[^A-Za-z0-9]/', '', (string)$error->getCode()) . ').' . PHP_EOL);
    } else {
        fwrite(STDERR, 'Administrator provisioning failed: ' . $error->getMessage() . PHP_EOL);
    }
    $exitCode = 1;
} finally {
    if ($pdo instanceof PDO && $lockName !== null) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
            if ((int)$release->fetchColumn() !== 1) {
                fwrite(STDERR, 'Could not release the initial administrator provisioning lock cleanly.' . PHP_EOL);
                $exitCode = 1;
            }
        } catch (Throwable $error) {
            fwrite(STDERR, 'Could not release the initial administrator provisioning lock cleanly.' . PHP_EOL);
            $exitCode = 1;
        }
    }
}

exit($exitCode);
