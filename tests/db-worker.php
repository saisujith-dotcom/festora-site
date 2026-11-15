<?php
if (PHP_SAPI !== 'cli' || $argc !== 5) {
    exit(2);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/event-operations.php';

$database = $argv[1];
$operation = $argv[2];
$eventId = filter_var($argv[3], FILTER_VALIDATE_INT);
$value = filter_var($argv[4], FILTER_VALIDATE_INT);
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) || $eventId === false || $value === false
    || !in_array($operation, ['register', 'capacity'], true)) {
    exit(3);
}

$deadline = microtime(true) + 10;
$barrier = getenv('FESTORA_TEST_BARRIER');
while (is_string($barrier) && $barrier !== '' && !is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (is_string($barrier) && $barrier !== '' && !is_file($barrier)) {
    exit(4);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . (int)DB_PORT . ';dbname=' . $database . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if ($operation === 'register') {
        $result = register_for_event($pdo, $eventId, $value, 'register', date('Y-m-d'));
        echo $result['status'];
    } else {
        $statement = $pdo->prepare('SELECT title, description, category, venue, event_date, start_time, end_time, image_url FROM events WHERE id=?');
        $statement->execute([$eventId]);
        $event = $statement->fetch();
        if (!$event) {
            exit(5);
        }
        $event['capacity'] = $value;
        echo update_event_with_capacity($pdo, $eventId, $event) ? 'updated' : 'rejected';
    }
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error));
    exit(1);
}
