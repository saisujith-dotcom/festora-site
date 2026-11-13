<?php

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
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $statement->execute([$eventId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('Event no longer exists.');
        }

        $statement = $pdo->prepare(
            "SELECT id FROM registrations WHERE event_id=? AND status='registered' FOR UPDATE"
        );
        $statement->execute([$eventId]);
        $activeIds = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($event['capacity'] < count($activeIds)) {
            $pdo->rollBack();
            return false;
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
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
