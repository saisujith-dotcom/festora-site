<?php

require_once __DIR__ . '/permissions.php';

/**
 * Register or cancel a student's registration while serializing changes on the event row.
 *
 * @return array{status:string,title?:string}
 */
function register_for_event(PDO $pdo, int $eventId, int $userId, string $action, string $today): array {
    if (!in_array($action, ['register', 'cancel'], true)) {
        $action = 'register';
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM events WHERE id=? FOR UPDATE');
        $statement->execute([$eventId]);
        $event = $statement->fetch();
        if (!$event) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $statement = $pdo->prepare('SELECT * FROM registrations WHERE event_id=? AND user_id=? FOR UPDATE');
        $statement->execute([$eventId, $userId]);
        $existing = $statement->fetch();

        if ($action === 'cancel') {
            if ($existing && $existing['status'] === 'registered') {
                $pdo->prepare("UPDATE registrations SET status='cancelled' WHERE id=?")
                    ->execute([$existing['id']]);
                $pdo->commit();
                return ['status' => 'cancelled'];
            }
            $pdo->commit();
            return ['status' => 'unchanged'];
        }

        if ($event['event_date'] < $today) {
            $pdo->commit();
            return ['status' => 'past'];
        }
        if ($existing && $existing['status'] === 'registered') {
            $pdo->commit();
            return ['status' => 'duplicate'];
        }

        $statement = $pdo->prepare(
            "SELECT id FROM registrations WHERE event_id=? AND status='registered' FOR UPDATE"
        );
        $statement->execute([$eventId]);
        $activeIds = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($activeIds) >= (int)$event['capacity']) {
            $pdo->commit();
            return ['status' => 'full'];
        }

        if ($existing) {
            $pdo->prepare("UPDATE registrations SET status='registered', registered_at=NOW() WHERE id=?")
                ->execute([$existing['id']]);
        } else {
            $pdo->prepare('INSERT INTO registrations (event_id, user_id) VALUES (?, ?)')
                ->execute([$eventId, $userId]);
        }

        $pdo->commit();
        return ['status' => 'confirmed', 'title' => (string)$event['title']];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Update an event under the same event-row lock used by registration.
 *
 * @param array{title:string,description:string,category:string,venue:string,event_date:string,start_time:string,end_time:?string,capacity:int,image_url:?string} $event
 * @return bool False when active registrations exceed the requested capacity.
 */
function update_event_with_capacity(PDO $pdo, int $eventId, array $event): bool {
    // No actor: callers of this form (CLI tools, the concurrency worker) have already established authority.
    $result = update_event_locked($pdo, null, $eventId, $event);
    if ($result === 'not_found') {
        throw new RuntimeException('Event no longer exists.');
    }
    return $result === 'updated';
}

/**
 * Update an event only if the administrator may manage it. The permission is re-checked under a row lock
 * inside the update transaction, so an assignment revoked after the page loaded cannot be used to save.
 *
 * @param array{id:int|string,role:string} $user
 * @param array{title:string,description:string,category:string,venue:string,event_date:string,start_time:string,end_time:?string,capacity:int,image_url:?string} $event
 * @return string 'updated' | 'capacity' | 'not_found' | 'forbidden'
 */
function update_event_for_admin(PDO $pdo, array $user, int $eventId, array $event): string {
    return update_event_locked($pdo, $user, $eventId, $event);
}

/**
 * Shared update transaction. With a non-null $user the permission is verified after the event row lock is
 * taken (same lock order as delete_event_for_admin and the assignment operations).
 *
 * @param array{id:int|string,role:string}|null $user
 * @return string 'updated' | 'capacity' | 'not_found' | 'forbidden'
 */
function update_event_locked(PDO $pdo, ?array $user, int $eventId, array $event): string {
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $statement->execute([$eventId]);
        if (!$statement->fetchColumn()) {
            $pdo->rollBack();
            return 'not_found';
        }
        if ($user !== null && !admin_can_manage_event($pdo, $user, $eventId, true)) {
            $pdo->rollBack();
            return 'forbidden';
        }

        $statement = $pdo->prepare(
            "SELECT id FROM registrations WHERE event_id=? AND status='registered' FOR UPDATE"
        );
        $statement->execute([$eventId]);
        $activeIds = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($event['capacity'] < count($activeIds)) {
            $pdo->rollBack();
            return 'capacity';
        }

        $statement = $pdo->prepare(
            'UPDATE events
             SET title=?, description=?, category=?, venue=?, event_date=?, start_time=?, end_time=?,
                 capacity=?, image_url=?
             WHERE id=?'
        );
        $statement->execute([
            $event['title'],
            $event['description'],
            $event['category'],
            $event['venue'],
            $event['event_date'],
            $event['start_time'],
            $event['end_time'],
            $event['capacity'],
            $event['image_url'],
            $eventId,
        ]);
        $pdo->commit();
        return 'updated';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Create an event owned by an administrator. An Event Admin is also assigned to the event so they can
 * manage it; a Super Admin manages every event and needs no assignment row.
 *
 * @param array{id:int|string,role:string} $user
 * @param array{title:string,description:string,category:string,venue:string,event_date:string,start_time:string,end_time:?string,capacity:int,image_url:?string} $event
 */
function create_event_for_admin(PDO $pdo, array $user, array $event): int {
    if (!in_array($user['role'] ?? null, ['event_admin', 'super_admin'], true)) {
        throw new RuntimeException('Only administrators may create events.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO events
             (title, description, category, venue, event_date, start_time, end_time, capacity, image_url, organizer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $event['title'], $event['description'], $event['category'], $event['venue'],
            $event['event_date'], $event['start_time'], $event['end_time'], $event['capacity'],
            $event['image_url'], (int)$user['id'],
        ]);
        $eventId = (int)$pdo->lastInsertId();
        if ($user['role'] === 'event_admin') {
            $pdo->prepare('INSERT INTO event_admin_assignments (event_id, admin_id, assigned_by) VALUES (?, ?, ?)')
                ->execute([$eventId, (int)$user['id'], (int)$user['id']]);
        }
        $pdo->commit();
        return $eventId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Delete an event (its schedule, announcements, assignments and registrations cascade with it) only if
 * the administrator may manage it. The permission is re-checked under a row lock inside the transaction.
 *
 * @param array{id:int|string,role:string} $user
 * @return string 'deleted' | 'not_found' | 'forbidden'
 */
function delete_event_for_admin(PDO $pdo, array $user, int $eventId): string {
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $statement->execute([$eventId]);
        if ($statement->fetchColumn() === false) {
            $pdo->rollBack();
            return 'not_found';
        }
        if (!admin_can_manage_event($pdo, $user, $eventId, true)) {
            $pdo->rollBack();
            return 'forbidden';
        }
        $pdo->prepare('DELETE FROM events WHERE id=?')->execute([$eventId]);
        $pdo->commit();
        return 'deleted';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
