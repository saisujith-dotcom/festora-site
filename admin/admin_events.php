<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-management.php';
$user = require_super_admin();

$adminId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$s = db()->prepare("SELECT id, name, email, role, is_active FROM users WHERE id=?");
$s->execute([$adminId]);
$admin = $s->fetch();
if (!$admin) { http_response_code(404); exit('Account not found.'); }
if ($admin['role'] !== 'event_admin') { http_response_code(403); exit('Only Event Admin accounts can be managed here.'); }
$back = 'admin/admin_events.php?id=' . $adminId;
$actorId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $eventId = (int)($_POST['event_id'] ?? 0);
    switch ((string)($_POST['action'] ?? '')) {
        case 'assign':
            $result = admin_assign_event(db(), $actorId, $eventId, $adminId);
            break;
        case 'unassign':
            $result = admin_unassign_event(db(), $actorId, $eventId, $adminId);
            break;
        case 'reassign':
            $result = admin_reassign_event(db(), $actorId, $eventId, $adminId, (int)($_POST['to_id'] ?? 0));
            break;
        case 'transfer_all':
            $result = admin_transfer_events(db(), $actorId, $adminId, (int)($_POST['to_id'] ?? 0));
            break;
        default:
            http_response_code(400);
            exit('Unknown action.');
    }
    flash(...admin_status_message($result['status']));
    redirect($back);
}

$assigned = db()->prepare("
    SELECT e.id, e.title, e.event_date, e.organizer_id
    FROM event_admin_assignments a JOIN events e ON e.id=a.event_id
    WHERE a.admin_id=? ORDER BY e.event_date DESC, e.id");
$assigned->execute([$adminId]);
$assigned = $assigned->fetchAll();

$available = db()->prepare("
    SELECT e.id, e.title, e.event_date FROM events e
    WHERE NOT EXISTS (SELECT 1 FROM event_admin_assignments a WHERE a.event_id=e.id AND a.admin_id=?)
    ORDER BY e.event_date DESC, e.id");
$available->execute([$adminId]);
$available = $available->fetchAll();

$owned = db()->prepare("SELECT COUNT(*) FROM events WHERE organizer_id=?");
$owned->execute([$adminId]);
$owned = (int)$owned->fetchColumn();

$others = db()->prepare("
    SELECT id, name, email, role FROM users
    WHERE role IN ('event_admin','super_admin') AND is_active=1 AND id<>? ORDER BY role DESC, name, id");
$others->execute([$adminId]);
$others = $others->fetchAll();
$otherEventAdmins = array_values(array_filter($others, static fn(array $o): bool => $o['role'] === 'event_admin'));

$pageTitle = 'Event assignments';
require __DIR__ . '/../includes/header.php';
$active = (int)$admin['is_active'] === 1;
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Super Admin console</p>
        <h1>Event assignments</h1>
        <p class="lede"><?= e($admin['name']) ?> &middot; <?= e($admin['email']) ?>
            <span class="status-pill <?= $active ? 'ok' : '' ?>"><?= $active ? 'Active' : 'Deactivated' ?></span></p>
    </div>
    <a class="btn btn-outline" href="<?= url('admin/admins.php') ?>">Back</a>
</div>

<h2>Assigned events</h2>
<p class="lede">An Event Admin can manage participants, schedules, announcements and details of assigned events only.</p>
<div class="table-wrap">
<table class="table table-stack">
    <thead><tr><th scope="col">Event</th><th scope="col">Date</th><th scope="col">Role</th><th scope="col">Actions</th></tr></thead>
    <tbody>
    <?php if (!$assigned): ?><tr><td colspan="4" class="empty">No events assigned.</td></tr><?php endif; ?>
    <?php foreach ($assigned as $ev): $isOwner = (int)$ev['organizer_id'] === $adminId; ?>
        <tr>
            <td data-label="Event"><a href="<?= url('event.php?id=' . (int)$ev['id']) ?>"><?= e($ev['title']) ?></a></td>
            <td data-label="Date"><?= e(fmt_date($ev['event_date'])) ?></td>
            <td data-label="Role"><span class="status-pill <?= $isOwner ? 'ok' : '' ?>"><?= $isOwner ? 'Owner' : 'Assigned' ?></span></td>
            <td data-label="Actions"><div class="actions">
                <?php if ($otherEventAdmins): ?>
                <form method="post" class="actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $adminId ?>">
                    <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                    <input type="hidden" name="action" value="reassign">
                    <label class="sr-only" for="reassign-<?= (int)$ev['id'] ?>">Reassign to</label>
                    <select id="reassign-<?= (int)$ev['id'] ?>" name="to_id" required>
                        <?php foreach ($otherEventAdmins as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline btn-sm" type="submit">Reassign</button>
                </form>
                <?php endif; ?>
                <?php if (!$isOwner): ?>
                <form method="post" data-confirm="Remove this assignment?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $adminId ?>">
                    <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                    <input type="hidden" name="action" value="unassign">
                    <button class="btn btn-danger btn-sm" type="submit">Unassign</button>
                </form>
                <?php endif; ?>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="split">
<div>
<h2>Assign another event</h2>
<?php if (!$active): ?>
    <p class="empty">Reactivate this account before assigning events.</p>
<?php elseif (!$available): ?>
    <p class="empty">Every event is already assigned to this administrator.</p>
<?php else: ?>
<div class="form-card wide">
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $adminId ?>">
        <input type="hidden" name="action" value="assign">
        <label for="assign-event">Event</label>
        <select id="assign-event" name="event_id" required>
            <?php foreach ($available as $ev): ?><option value="<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?> (<?= e(fmt_date($ev['event_date'])) ?>)</option><?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Assign event</button>
    </form>
</div>
<?php endif; ?>
</div>

<div>
<h2>Transfer ownership</h2>
<p class="lede"><?= $owned ?> event<?= $owned === 1 ? '' : 's' ?> owned by this administrator. Transferring ownership never deletes events or registrations.</p>
<?php if ($owned && $others): ?>
<div class="form-card wide">
    <form method="post" data-confirm="Transfer ownership of all their events?" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $adminId ?>">
        <input type="hidden" name="action" value="transfer_all">
        <label for="transfer-to">New owner</label>
        <select id="transfer-to" name="to_id" required>
            <?php foreach ($others as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?> (<?= $o['role'] === 'super_admin' ? 'Super Admin' : 'Event Admin' ?>)</option><?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Transfer all owned events</button>
    </form>
</div>
<?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
