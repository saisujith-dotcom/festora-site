<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/event-operations.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('events.php');
csrf_check();
$user = require_login();
if ($user['role'] !== 'student') { flash('Only students can register for events.', 'error'); redirect('events.php'); }

$eventId = (int)($_POST['event_id'] ?? 0);
$action  = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'register';
$back = 'event.php?id=' . $eventId;
$pdo = db();

try {
    $result = register_for_event($pdo, $eventId, (int)$user['id'], $action, date('Y-m-d'));
    switch ($result['status']) {
        case 'not_found':
            flash('Event not found.', 'error');
            redirect('events.php');
        case 'cancelled':
            flash('Your registration has been cancelled.');
            break;
        case 'past':
            flash('This event has already taken place.', 'error');
            break;
        case 'duplicate':
            flash('You are already registered for this event.', 'error');
            break;
        case 'full':
            flash('Sorry, this event is full.', 'error');
            break;
        case 'confirmed':
            flash('Registration confirmed for ' . $result['title'] . '!');
            break;
    }
} catch (Throwable $error) {
    festora_log_error('registration', $error);
    flash('Something went wrong. Please try again.', 'error');
}
redirect($back);
