<?php
/**
 * Event-specific permissions.
 *
 *  - Super Admin: may manage every event.
 *  - Event Admin: may manage only the events they are assigned to (event_admin_assignments).
 *  - Everyone else (students, anonymous visitors, legacy roles): no administrative access at all.
 *
 * Every admin controller must go through these helpers; nothing may rely on a hidden form field or a
 * link that was merely not rendered.
 */

/**
 * @param array{id:int|string,role:string} $user A user already validated by current_user().
 * @param bool $lock Take a row lock (only meaningful inside a transaction).
 */
function admin_can_manage_event(PDO $pdo, array $user, int $eventId, bool $lock = false): bool {
    if ($eventId < 1 || !isset($user['id'], $user['role'])) {
        return false;
    }
    $suffix = $lock ? ' FOR UPDATE' : '';

    if ($user['role'] === 'super_admin') {
        $statement = $pdo->prepare('SELECT 1 FROM events WHERE id=?' . $suffix);
        $statement->execute([$eventId]);
        return $statement->fetchColumn() !== false;
    }
    if ($user['role'] !== 'event_admin') {
        return false;
    }
    $statement = $pdo->prepare('SELECT 1 FROM event_admin_assignments WHERE event_id=? AND admin_id=?' . $suffix);
    $statement->execute([$eventId, (int)$user['id']]);
    return $statement->fetchColumn() !== false;
}

/**
 * Load an event for an administrator or stop the request: 404 when it does not exist, 403 when the
 * administrator is not allowed to manage it.
 *
 * @return array<string,mixed>
 */
function require_event_manager(array $user, int $eventId): array {
    $statement = db()->prepare('SELECT * FROM events WHERE id=?');
    $statement->execute([$eventId]);
    $event = $statement->fetch();
    if (!$event) {
        http_response_code(404);
        exit('Event not found.');
    }
    if (!admin_can_manage_event(db(), $user, $eventId)) {
        http_response_code(403);
        exit('Access denied: you are not assigned to this event.');
    }
    return $event;
}

/**
 * SQL condition limiting an events query to what $user may manage.
 *
 * @return array{0:string,1:list<int>} [condition, positional parameters]
 */
function admin_event_scope(array $user, string $alias = 'e'): array {
    if (!preg_match('/^[a-z_]{1,10}$/', $alias)) {
        throw new InvalidArgumentException('Invalid table alias.');
    }
    if (($user['role'] ?? '') === 'super_admin') {
        return ['1=1', []];
    }
    if (($user['role'] ?? '') === 'event_admin') {
        return [$alias . '.id IN (SELECT event_id FROM event_admin_assignments WHERE admin_id = ?)', [(int)$user['id']]];
    }
    return ['1=0', []];
}
