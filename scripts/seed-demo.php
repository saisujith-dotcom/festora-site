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
    if (!festora_is_development()) {
        throw new RuntimeException('Demo seeding is allowed only when FESTORA_ENV=development.');
    }

    $database = festora_cli_database_name($argv);
    $pdo = festora_cli_connect($database);
    $lockName = festora_acquire_migration_lock($pdo, $database);
    $migrations = festora_migration_files(__DIR__ . '/../database/migrations');
    $history = festora_read_migration_history($pdo, $migrations);
    festora_assert_all_migrations_applied($migrations, $history);
    festora_assert_application_schema($pdo);

    foreach (FESTORA_APPLICATION_TABLES as $table) {
        if ((int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 0) {
            throw new RuntimeException('Application tables are not empty; refusing to seed demo data.');
        }
    }

    $pdo->beginTransaction();

    $user = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, enrollment_no, branch)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $user->execute([
        'Festora Organizer',
        'organizer@festora.test',
        '$2y$10$4T1e1fe37FqBmiPPYZcZne38oeRjZwig.b4KGGya1bFsovlv8.hNe',
        'organizer',
        null,
        null,
    ]);
    $organizerId = (int)$pdo->lastInsertId();
    $user->execute([
        'Demo Student',
        'student@festora.test',
        '$2y$10$pwAL6m0JCnLJcx8RLx/7f.NA0/JNEH1/tsoGTkrkvwLQDmj9ewllu',
        'student',
        '25CS000001',
        'B.Tech CSE',
    ]);
    $studentId = (int)$pdo->lastInsertId();

    $events = [
        ['CodeSprint 2026', 'A 4-hour competitive programming contest with problems on algorithms and data structures. Individual participation; bring your own laptop.', 'Technical', 'Computer Lab 2', 5, '10:00:00', '14:00:00', 60],
        ['Rhythm Night', 'An evening of music, dance and stand-up performances by student clubs. Open to all departments.', 'Cultural', 'Main Auditorium', 9, '17:30:00', '21:00:00', 300],
        ['Inter-Department Cricket Cup', 'Knockout T10 tournament. Teams of 8 players; register as a participant and the sports committee will form teams.', 'Sports', 'College Ground', 14, '08:00:00', '16:00:00', 64],
        ['Web Development Bootcamp', 'Hands-on one-day workshop covering HTML5, CSS3, JavaScript and PHP with MySQL. Build and deploy a small project.', 'Workshop', 'Seminar Hall B', 3, '09:30:00', '16:30:00', 40],
        ['AI in Everyday Life', 'Guest lecture by an industry speaker on practical applications of machine learning, followed by a Q&A session.', 'Seminar', 'Conference Room 1', 20, '11:00:00', '13:00:00', 4],
    ];
    $eventInsert = $pdo->prepare(
        'INSERT INTO events
         (title, description, category, venue, event_date, start_time, end_time, capacity, image_url, organizer_id)
         VALUES (?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, ?, ?, NULL, ?)'
    );
    $eventIds = [];
    foreach ($events as $event) {
        $eventInsert->execute([
            $event[0], $event[1], $event[2], $event[3], $event[4],
            $event[5], $event[6], $event[7], $organizerId,
        ]);
        $eventIds[$event[0]] = (int)$pdo->lastInsertId();
    }

    $scheduleItems = [
        ['CodeSprint 2026', '09:30:00', 'Check-in and setup'],
        ['CodeSprint 2026', '10:00:00', 'Contest begins'],
        ['CodeSprint 2026', '14:00:00', 'Contest ends and results'],
        ['Web Development Bootcamp', '09:30:00', 'Registration and welcome'],
        ['Web Development Bootcamp', '10:00:00', 'HTML5 and CSS3 basics'],
        ['Web Development Bootcamp', '13:00:00', 'Lunch break'],
        ['Web Development Bootcamp', '14:00:00', 'JavaScript, PHP and MySQL project'],
        ['Rhythm Night', '17:30:00', 'Doors open'],
        ['Rhythm Night', '18:00:00', 'Performances begin'],
    ];
    $scheduleInsert = $pdo->prepare(
        'INSERT INTO schedule_items (event_id, item_time, title) VALUES (?, ?, ?)'
    );
    foreach ($scheduleItems as $item) {
        $scheduleInsert->execute([$eventIds[$item[0]], $item[1], $item[2]]);
    }

    $announcementInsert = $pdo->prepare(
        'INSERT INTO announcements (event_id, title, message) VALUES (?, ?, ?)'
    );
    $announcementInsert->execute([
        null,
        'Welcome to Festora',
        'Browse upcoming events, register online and track your registrations from your dashboard.',
    ]);
    $announcementInsert->execute([
        $eventIds['Web Development Bootcamp'],
        'Bring your laptop',
        'Please bring a laptop with a local PHP 8.4 and MySQL 8.4 setup for the bootcamp.',
    ]);
    $announcementInsert->execute([
        $eventIds['CodeSprint 2026'],
        'Rules published',
        'Contest rules and language list will be shared with registered participants one day before the event.',
    ]);

    $registration = $pdo->prepare(
        'INSERT INTO registrations (event_id, user_id) VALUES (?, ?)'
    );
    $registration->execute([$eventIds['Web Development Bootcamp'], $studentId]);

    $pdo->commit();
    echo 'Demo data inserted.' . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(
        STDERR,
        'Demo seeding failed (' . get_class($error) . ', code ' . festora_database_error_code($error) . '): '
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
