<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db-common.php';

function festora_admin_arguments(array $argv): array {
    $values = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--password-stdin') {
            if (isset($values['password_stdin'])) {
                throw new InvalidArgumentException('Specify --password-stdin once.');
            }
            $values['password_stdin'] = true;
            continue;
        }
        if (!preg_match('/^--(database|email|name)=(.*)$/s', $argument, $matches)) {
            throw new InvalidArgumentException('Only --database, --email, --name and --password-stdin are supported.');
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
    $values['database'] = festora_cli_database_name(['provision-admin.php', '--database=' . $values['database']]);
    $values['email'] = strtolower(trim($values['email']));
    $values['name'] = trim($values['name']);

    if (strlen($values['email']) > 150 || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('A valid email address is required.');
    }
    if (mb_strlen($values['name']) < 2 || mb_strlen($values['name']) > 100
        || preg_match('/[\x00-\x1F\x7F]/', $values['name'])) {
        throw new InvalidArgumentException('Name must be between 2 and 100 characters.');
    }

    return $values;
}

$pdo = null;
try {
    $options = festora_admin_arguments($argv);
    $password = fgets(STDIN);
    if (!is_string($password)) {
        throw new RuntimeException('Password input is required on standard input.');
    }
    $password = rtrim($password, "\r\n");
    if (strlen($password) < 12 || strlen($password) > 4096) {
        throw new InvalidArgumentException('Password must be between 12 and 4096 bytes.');
    }

    $pdo = festora_cli_connect($options['database']);
    $migrations = festora_migration_files(__DIR__ . '/../database/migrations');
    $history = festora_read_migration_history($pdo, $migrations);
    festora_assert_all_migrations_applied($migrations, $history);
    festora_assert_application_schema($pdo);

    $statement = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $statement->execute([$options['email']]);
    if ($statement->fetchColumn()) {
        throw new RuntimeException('An account with that email already exists; no account was changed.');
    }

    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role)
         VALUES (?, ?, ?, 'organizer')"
    );
    $statement->execute([
        $options['name'],
        $options['email'],
        password_hash($password, PASSWORD_DEFAULT),
    ]);
    echo 'Organizer administrator account created.' . PHP_EOL;
} catch (Throwable $error) {
    if ($error instanceof PDOException) {
        fwrite(STDERR, 'Administrator provisioning failed because of a database error ('
            . preg_replace('/[^A-Za-z0-9]/', '', (string)$error->getCode()) . ').' . PHP_EOL);
    } else {
        fwrite(STDERR, 'Administrator provisioning failed: ' . $error->getMessage() . PHP_EOL);
    }
    exit(1);
}
