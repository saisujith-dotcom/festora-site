<?php
/**
 * Super Admin operations on Event Admin accounts and event assignments.
 *
 * Requires includes/bootstrap.php (or any caller that has loaded config.php and account-validation.php).
 * Every function takes the PDO handle and the acting Super Admin's id, and re-verifies inside its own
 * transaction that the actor is still an ACTIVE Super Admin. Every function returns ['status' => string, ...]
 * and changes nothing unless the status says so.
 *
 * Locking: every operation first locks all Super Admin rows in id order, then any other rows it needs
 * (users by id, then events by id). One global order means concurrent operations serialize instead of
 * deadlocking, and the "last active Super Admin" rule cannot be raced.
 *
 * Data safety: events.organizer_id is ON DELETE RESTRICT (migration 010). Deleting an administrator can
 * therefore never cascade into events or registrations; an owner's events must be transferred first.
 */

require_once __DIR__ . '/account-validation.php';

/** @return array<int,array<string,mixed>> Super Admin rows by id, all locked FOR UPDATE. */
function admin_lock_super_admins(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, role, is_active FROM users WHERE role='super_admin' ORDER BY id FOR UPDATE")->fetchAll();
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['id']] = $row;
    }
    return $byId;
}

/** @return array<string,mixed>|null */
function admin_lock_user(PDO $pdo, int $id): ?array {
    $statement = $pdo->prepare('SELECT id, name, email, role, is_active FROM users WHERE id=? FOR UPDATE');
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/** True when $actorId is an active Super Admin among the already-locked rows. */
function admin_actor_is_active_super_admin(array $lockedSuperAdmins, int $actorId): bool {
    return isset($lockedSuperAdmins[$actorId]) && (int)$lockedSuperAdmins[$actorId]['is_active'] === 1;
}

/**
 * True when deactivating or deleting $targetId would leave the system with no active Super Admin.
 * An inactive or non-Super-Admin target can never be "the last active Super Admin".
 */
function admin_would_remove_last_super_admin(PDO $pdo, int $targetId): bool {
    $statement = $pdo->prepare('SELECT role, is_active FROM users WHERE id=?');
    $statement->execute([$targetId]);
    $target = $statement->fetch();
    if (!$target || $target['role'] !== 'super_admin' || (int)$target['is_active'] !== 1) {
        return false;
    }
    $statement = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='super_admin' AND is_active=1 AND id<>?");
    $statement->execute([$targetId]);
    return (int)$statement->fetchColumn() === 0;
}

/** Run $operation inside a transaction that has already verified the actor; roll back on any non-commit. */
function admin_transaction(PDO $pdo, int $actorId, callable $operation): array {
    $pdo->beginTransaction();
    try {
        $supers = admin_lock_super_admins($pdo);
        if (!admin_actor_is_active_super_admin($supers, $actorId)) {
            $pdo->rollBack();
            return ['status' => 'forbidden'];
        }
        $result = $operation($pdo, $supers);
        if ($pdo->inTransaction()) {
            if (in_array($result['status'], ADMIN_COMMIT_STATUSES, true)) {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        }
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Statuses that mean "the change was made"; every other status rolls the transaction back. */
const ADMIN_COMMIT_STATUSES = [
    'created', 'updated', 'deactivated', 'reactivated', 'assigned', 'unassigned',
    'reassigned', 'transferred', 'deleted',
];

/** @return array{status:string,id?:int,errors?:list<string>} */
function admin_create_event_admin(PDO $pdo, int $actorId, string $name, string $email, string $password): array {
    $name = trim($name);
    $email = festora_normalize_email($email);
    $errors = array_merge(festora_account_field_errors($name, $email), festora_password_policy_errors($password, $email));
    if (!festora_is_development() && is_demo_email($email)) {
        $errors[] = 'This email domain is reserved. Use a real email address.';
    }
    if ($errors) {
        return ['status' => 'invalid', 'errors' => $errors];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        return admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($name, $email, $hash, $actorId): array {
            $statement = $pdo->prepare('SELECT id FROM users WHERE email=? FOR UPDATE');
            $statement->execute([$email]);
            if ($statement->fetchColumn() !== false) {
                return ['status' => 'duplicate_email'];
            }
            $pdo->prepare("INSERT INTO users (name, email, password_hash, role, created_by) VALUES (?, ?, ?, 'event_admin', ?)")
                ->execute([$name, $email, $hash, $actorId]);
            return ['status' => 'created', 'id' => (int)$pdo->lastInsertId()];
        });
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') {
            return ['status' => 'duplicate_email'];
        }
        throw $error;
    }
}

/** @return array{status:string,errors?:list<string>} */
function admin_update_event_admin(PDO $pdo, int $actorId, int $targetId, string $name, string $email): array {
    $name = trim($name);
    $email = festora_normalize_email($email);
    $errors = festora_account_field_errors($name, $email);
    if (!festora_is_development() && is_demo_email($email)) {
        $errors[] = 'This email domain is reserved. Use a real email address.';
    }
    if ($errors) {
        return ['status' => 'invalid', 'errors' => $errors];
    }

    try {
        return admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($targetId, $name, $email): array {
            $target = admin_lock_user($pdo, $targetId);
            if ($target === null) {
                return ['status' => 'not_found'];
            }
            if ($target['role'] !== 'event_admin') {
                return ['status' => 'not_event_admin'];
            }
            $statement = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? FOR UPDATE');
            $statement->execute([$email, $targetId]);
            if ($statement->fetchColumn() !== false) {
                return ['status' => 'duplicate_email'];
            }
            $pdo->prepare('UPDATE users SET name=?, email=? WHERE id=?')->execute([$name, $email, $targetId]);
            return ['status' => 'updated'];
        });
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') {
            return ['status' => 'duplicate_email'];
        }
        throw $error;
    }
}

/**
 * Deactivate or reactivate an administrator account.
 * Statuses: deactivated, reactivated, unchanged, self, last_super_admin, not_found, not_admin, forbidden.
 */
function admin_set_active(PDO $pdo, int $actorId, int $targetId, bool $active): array {
    return admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($actorId, $targetId, $active): array {
        if ($targetId === $actorId && !$active) {
            return ['status' => 'self'];
        }
        $target = admin_lock_user($pdo, $targetId);
        if ($target === null) {
            return ['status' => 'not_found'];
        }
        if (!is_admin_role($target['role'])) {
            return ['status' => 'not_admin'];
        }
        if (((int)$target['is_active'] === 1) === $active) {
            return ['status' => 'unchanged'];
        }
        if (!$active && admin_would_remove_last_super_admin($pdo, $targetId)) {
            return ['status' => 'last_super_admin'];
        }
        $pdo->prepare('UPDATE users SET is_active=?, disabled_at=' . ($active ? 'NULL' : 'CURRENT_TIMESTAMP') . ' WHERE id=?')
            ->execute([$active ? 1 : 0, $targetId]);
        return ['status' => $active ? 'reactivated' : 'deactivated'];
    });
}

/** Lock an event row; null when it does not exist. @return array<string,mixed>|null */
function admin_lock_event(PDO $pdo, int $eventId): ?array {
    $statement = $pdo->prepare('SELECT id, organizer_id FROM events WHERE id=? FOR UPDATE');
    $statement->execute([$eventId]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/** True for an existing, ACTIVE Event Admin. */
function admin_is_active_event_admin(?array $user): bool {
    return $user !== null && $user['role'] === 'event_admin' && (int)$user['is_active'] === 1;
}

/**
 * Give an Event Admin access to an event (in addition to anyone already assigned).
 * Statuses: assigned, already_assigned, not_found, invalid_admin, forbidden.
 */
function admin_assign_event(PDO $pdo, int $actorId, int $eventId, int $adminId): array {
    return admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($actorId, $eventId, $adminId): array {
        if (admin_lock_event($pdo, $eventId) === null) {
            return ['status' => 'not_found'];
        }
        if (!admin_is_active_event_admin(admin_lock_user($pdo, $adminId))) {
            return ['status' => 'invalid_admin'];
        }
        $statement = $pdo->prepare('SELECT 1 FROM event_admin_assignments WHERE event_id=? AND admin_id=? FOR UPDATE');
        $statement->execute([$eventId, $adminId]);
        if ($statement->fetchColumn() !== false) {
            return ['status' => 'already_assigned'];
        }
        $pdo->prepare('INSERT INTO event_admin_assignments (event_id, admin_id, assigned_by) VALUES (?, ?, ?)')
            ->execute([$eventId, $adminId, $actorId]);
        return ['status' => 'assigned'];
    });
}

/**
 * Remove an Event Admin's access to an event. The event's owner cannot be unassigned; reassign the event
 * (or transfer ownership) first.
 * Statuses: unassigned, not_assigned, is_owner, not_found, forbidden.
 */
function admin_unassign_event(PDO $pdo, int $actorId, int $eventId, int $adminId): array {
    return admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($eventId, $adminId): array {
        $event = admin_lock_event($pdo, $eventId);
        if ($event === null) {
            return ['status' => 'not_found'];
        }
        if ((int)$event['organizer_id'] === $adminId) {
            return ['status' => 'is_owner'];
        }
        $statement = $pdo->prepare('DELETE FROM event_admin_assignments WHERE event_id=? AND admin_id=?');
        $statement->execute([$eventId, $adminId]);
        return $statement->rowCount() === 1 ? ['status' => 'unassigned'] : ['status' => 'not_assigned'];
    });
}

/**
 * Move an event from one Event Admin to another: the new admin is assigned, the old admin loses access,
 * and if the old admin owned the event the new admin becomes its owner. Other assignees are untouched.
 * Statuses: reassigned, same_admin, not_assigned, invalid_admin, not_found, forbidden.
 */
function admin_reassign_event(PDO $pdo, int $actorId, int $eventId, int $fromAdminId, int $toAdminId): array {
    return admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($actorId, $eventId, $fromAdminId, $toAdminId): array {
        if ($fromAdminId === $toAdminId) {
            return ['status' => 'same_admin'];
        }
        $event = admin_lock_event($pdo, $eventId);
        if ($event === null) {
            return ['status' => 'not_found'];
        }
        $from = admin_lock_user($pdo, $fromAdminId);
        $to = admin_lock_user($pdo, $toAdminId);
        if (!admin_is_active_event_admin($to)) {
            return ['status' => 'invalid_admin'];
        }
        $statement = $pdo->prepare('SELECT 1 FROM event_admin_assignments WHERE event_id=? AND admin_id=? FOR UPDATE');
        $statement->execute([$eventId, $fromAdminId]);
        $assigned = $statement->fetchColumn() !== false;
        if ($from === null || (!$assigned && (int)$event['organizer_id'] !== $fromAdminId)) {
            return ['status' => 'not_assigned'];
        }
        $pdo->prepare('INSERT IGNORE INTO event_admin_assignments (event_id, admin_id, assigned_by) VALUES (?, ?, ?)')
            ->execute([$eventId, $toAdminId, $actorId]);
        $pdo->prepare('DELETE FROM event_admin_assignments WHERE event_id=? AND admin_id=?')
            ->execute([$eventId, $fromAdminId]);
        if ((int)$event['organizer_id'] === $fromAdminId) {
            $pdo->prepare('UPDATE events SET organizer_id=? WHERE id=?')->execute([$toAdminId, $eventId]);
        }
        return ['status' => 'reassigned'];
    });
}

/**
 * Hand every event owned by $fromId to $toId (an active Event Admin or Super Admin). Events, their
 * registrations, schedules and announcements are untouched; only the owner changes. An Event Admin
 * receiving ownership is also assigned. The previous owner keeps any assignment until it is removed.
 * Must be called inside an admin_transaction().
 *
 * @return array{status:string,count?:int}
 */
function admin_transfer_owned_events_locked(PDO $pdo, int $actorId, int $fromId, int $toId): array {
    if ($fromId === $toId) {
        return ['status' => 'same_admin'];
    }
    $to = admin_lock_user($pdo, $toId);
    if ($to === null || (int)$to['is_active'] !== 1 || !is_admin_role($to['role'])) {
        return ['status' => 'invalid_target'];
    }
    $statement = $pdo->prepare('SELECT id FROM events WHERE organizer_id=? ORDER BY id FOR UPDATE');
    $statement->execute([$fromId]);
    $eventIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if (!$eventIds) {
        return ['status' => 'nothing_to_transfer', 'count' => 0];
    }
    $pdo->prepare('UPDATE events SET organizer_id=? WHERE organizer_id=?')->execute([$toId, $fromId]);
    if ($to['role'] === 'event_admin') {
        $assign = $pdo->prepare('INSERT IGNORE INTO event_admin_assignments (event_id, admin_id, assigned_by) VALUES (?, ?, ?)');
        foreach ($eventIds as $eventId) {
            $assign->execute([$eventId, $toId, $actorId]);
        }
    }
    return ['status' => 'transferred', 'count' => count($eventIds)];
}

/**
 * Transfer ownership of all of an administrator's events without deleting anything.
 * Statuses: transferred, nothing_to_transfer, same_admin, invalid_target, not_found, forbidden.
 */
function admin_transfer_events(PDO $pdo, int $actorId, int $fromId, int $toId): array {
    $result = admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($actorId, $fromId, $toId): array {
        $from = admin_lock_user($pdo, $fromId);
        if ($from === null || !is_admin_role($from['role'])) {
            return ['status' => 'not_found'];
        }
        return admin_transfer_owned_events_locked($pdo, $actorId, $fromId, $toId);
    });
    return $result;
}

/**
 * Transfer all event access and ownership before an administrator account is deleted.
 * The recipient is assigned to every affected event when they are an Event Admin; other assignments
 * remain in place. Must be called inside an admin_transaction().
 *
 * @return array{status:string,count?:int}
 */
function admin_transfer_account_events_locked(PDO $pdo, int $actorId, int $fromId, int $toId): array {
    if ($fromId === $toId) {
        return ['status' => 'same_admin'];
    }
    $to = admin_lock_user($pdo, $toId);
    if ($to === null || (int)$to['is_active'] !== 1 || !is_admin_role($to['role'])) {
        return ['status' => 'invalid_target'];
    }

    $owned = $pdo->prepare('SELECT id FROM events WHERE organizer_id=? ORDER BY id FOR UPDATE');
    $owned->execute([$fromId]);
    $eventIds = $owned->fetchAll(PDO::FETCH_COLUMN);

    $assigned = $pdo->prepare(
        'SELECT event_id FROM event_admin_assignments WHERE admin_id=? ORDER BY event_id FOR UPDATE'
    );
    $assigned->execute([$fromId]);
    $eventIds = array_values(array_unique(array_merge($eventIds, $assigned->fetchAll(PDO::FETCH_COLUMN))));
    if (!$eventIds) {
        return ['status' => 'nothing_to_transfer', 'count' => 0];
    }

    $events = $pdo->prepare('SELECT id, organizer_id FROM events WHERE id=? FOR UPDATE');
    $assignRecipient = $pdo->prepare(
        'INSERT IGNORE INTO event_admin_assignments (event_id, admin_id, assigned_by) VALUES (?, ?, ?)'
    );
    $removeSource = $pdo->prepare('DELETE FROM event_admin_assignments WHERE event_id=? AND admin_id=?');
    $transferOwner = $pdo->prepare('UPDATE events SET organizer_id=? WHERE id=?');
    $count = 0;
    foreach ($eventIds as $eventId) {
        $eventId = (int)$eventId;
        $events->execute([$eventId]);
        $event = $events->fetch();
        if ($event === false) {
            continue;
        }
        if ((int)$event['organizer_id'] === $fromId) {
            $transferOwner->execute([$toId, $eventId]);
        }
        if ($to['role'] === 'event_admin') {
            $assignRecipient->execute([$eventId, $toId, $actorId]);
        }
        $removeSource->execute([$eventId, $fromId]);
        $count++;
    }

    return ['status' => 'transferred', 'count' => $count];
}

/**
 * Delete an administrator account. Never deletes events or registrations:
 *  - an account with event ownership or assignments must name $transferToId, and access/ownership is
 *    transferred in the same transaction;
 *  - an account that has its own registrations is refused (deactivate it instead).
 * Statuses: deleted, self, last_super_admin, owns_events, has_registrations, invalid_target, same_admin,
 *           not_found, not_admin, forbidden.
 *
 * @return array{status:string,transferred?:int}
 */
function admin_delete(PDO $pdo, int $actorId, int $targetId, ?int $transferToId = null): array {
    return admin_transaction($pdo, $actorId, static function (PDO $pdo) use ($actorId, $targetId, $transferToId): array {
        if ($targetId === $actorId) {
            return ['status' => 'self'];
        }
        $target = admin_lock_user($pdo, $targetId);
        if ($target === null) {
            return ['status' => 'not_found'];
        }
        if (!is_admin_role($target['role'])) {
            return ['status' => 'not_admin'];
        }
        if (admin_would_remove_last_super_admin($pdo, $targetId)) {
            return ['status' => 'last_super_admin'];
        }
        if ($transferToId !== null) {
            if ($transferToId === $targetId) {
                return ['status' => 'same_admin'];
            }
            $recipient = admin_lock_user($pdo, $transferToId);
            if ($recipient === null || (int)$recipient['is_active'] !== 1 || !is_admin_role($recipient['role'])) {
                return ['status' => 'invalid_target'];
            }
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE user_id=?');
        $statement->execute([$targetId]);
        if ((int)$statement->fetchColumn() > 0) {
            return ['status' => 'has_registrations'];
        }

        $owned = $pdo->prepare('SELECT id FROM events WHERE organizer_id=? ORDER BY id FOR UPDATE');
        $owned->execute([$targetId]);
        $assigned = $pdo->prepare(
            'SELECT event_id FROM event_admin_assignments WHERE admin_id=? ORDER BY event_id FOR UPDATE'
        );
        $assigned->execute([$targetId]);
        $eventIds = array_values(array_unique(array_merge(
            $owned->fetchAll(PDO::FETCH_COLUMN),
            $assigned->fetchAll(PDO::FETCH_COLUMN)
        )));
        $transferred = 0;
        if ($eventIds) {
            if ($transferToId === null) {
                return ['status' => 'owns_events'];
            }
            $moved = admin_transfer_account_events_locked($pdo, $actorId, $targetId, $transferToId);
            if ($moved['status'] !== 'transferred') {
                return ['status' => $moved['status']];
            }
            $transferred = (int)$moved['count'];
        }

        $delete = $pdo->prepare('DELETE FROM users WHERE id=?');
        $delete->execute([$targetId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Administrator deletion did not affect exactly one row.');
        }
        return ['status' => 'deleted', 'transferred' => $transferred];
    });
}

/** Human-readable outcome for a management status: [message, flash type]. */
function admin_status_message(string $status): array {
    return match ($status) {
        'created' => ['Event Admin account created.', 'success'],
        'updated' => ['Event Admin account updated.', 'success'],
        'deactivated' => ['Account deactivated. The administrator is signed out and cannot log in.', 'success'],
        'reactivated' => ['Account reactivated.', 'success'],
        'unchanged' => ['Nothing changed.', 'error'],
        'assigned' => ['Event assigned.', 'success'],
        'already_assigned' => ['That administrator is already assigned to this event.', 'error'],
        'unassigned' => ['Assignment removed.', 'success'],
        'reassigned' => ['Event reassigned.', 'success'],
        'transferred' => ['Event ownership transferred.', 'success'],
        'nothing_to_transfer' => ['This administrator owns no events.', 'error'],
        'deleted' => ['Administrator deleted. Their events and all registrations were preserved.', 'success'],
        'self' => ['You cannot deactivate or delete your own account.', 'error'],
        'last_super_admin' => ['The last active Super Admin cannot be deactivated or deleted.', 'error'],
        'owns_events' => ['This administrator still has assigned or owned events. Choose who takes them over first.', 'error'],
        'has_registrations' => ['This account has event registrations, so it cannot be deleted. Deactivate it instead.', 'error'],
        'invalid_target', 'invalid_admin' => ['Choose an active administrator.', 'error'],
        'same_admin' => ['Choose a different administrator.', 'error'],
        'is_owner' => ['This administrator owns the event. Reassign the event instead.', 'error'],
        'not_assigned' => ['That administrator is not assigned to this event.', 'error'],
        'duplicate_email' => ['An account with that email already exists.', 'error'],
        'not_found' => ['Not found.', 'error'],
        'not_admin', 'not_event_admin' => ['Only Event Admin accounts can be managed here.', 'error'],
        'forbidden' => ['You are not allowed to do that.', 'error'],
        default => ['The change could not be made.', 'error'],
    };
}
