<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_admin();
$isSuper = is_super_admin_role($user['role']);
$pageTitle = $isSuper ? 'Super Admin Dashboard' : 'Event Admin Dashboard';

// Everything below is limited to the events this administrator may manage.
[$scope, $scopeParams] = admin_event_scope($user, 'e');
$events = db()->prepare("
    SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='registered') AS taken
    FROM events e WHERE $scope ORDER BY e.event_date DESC, e.start_time");
$events->execute($scopeParams);
$events = $events->fetchAll();

$today = date('Y-m-d');
$stats = ['events' => count($events), 'upcoming' => 0, 'regs' => 0];
foreach ($events as $ev) {
    if ($ev['event_date'] >= $today) $stats['upcoming']++;
    $stats['regs'] += (int)$ev['taken'];
}
$students = $isSuper ? (int)db()->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn() : 0;

$adminSummary = ['active' => 0, 'inactive' => 0];
$unassigned = 0;
if ($isSuper) {
    $adminSummary = db()->query("
        SELECT COALESCE(SUM(is_active=1), 0) AS active, COALESCE(SUM(is_active=0), 0) AS inactive
        FROM users WHERE role='event_admin'")->fetch();
    $unassigned = (int)db()->query("
        SELECT COUNT(*) FROM events e
        WHERE NOT EXISTS (SELECT 1 FROM event_admin_assignments a WHERE a.event_id=e.id)")->fetchColumn();
}
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= $isSuper ? 'Super Admin console' : 'Organiser console' ?></p>
        <h1><?= e($pageTitle) ?></h1>
    </div>
    <a class="btn" href="<?= url('admin/event_form.php') ?>">+ New event</a>
</div>

<div class="stats">
    <div class="stat"><strong><?= (int)$stats['events'] ?></strong><span><?= $isSuper ? 'Total events' : 'My events' ?></span></div>
    <div class="stat"><strong><?= (int)$stats['upcoming'] ?></strong><span>Upcoming</span></div>
    <div class="stat"><strong><?= (int)$stats['regs'] ?></strong><span>Active registrations</span></div>
    <?php if ($isSuper): ?>
    <div class="stat"><strong><?= $students ?></strong><span>Students</span></div>
    <?php endif; ?>
</div>

<?php if ($isSuper): ?>
<section class="section" aria-labelledby="event-admins-heading">
    <div class="section-head">
        <h2 id="event-admins-heading">Event Admins</h2>
        <a class="link-arrow" href="<?= url('admin/admins.php') ?>">Manage Event Admins <?= $navArrow ?></a>
    </div>
    <div class="stats">
        <div class="stat"><strong><?= (int)$adminSummary['active'] ?></strong><span>Active Event Admins</span></div>
        <div class="stat"><strong><?= (int)$adminSummary['inactive'] ?></strong><span>Deactivated</span></div>
        <div class="stat"><strong><?= $unassigned ?></strong><span>Events with no Event Admin</span></div>
    </div>
</section>
<?php endif; ?>

<div class="table-wrap">
<table class="table table-stack">
    <thead><tr><th scope="col">Event</th><th scope="col">Date</th><th scope="col">Category</th><th scope="col">Registered</th><th scope="col">Actions</th></tr></thead>
    <tbody>
    <?php if (!$events): ?><tr><td colspan="5" class="empty"><?= $isSuper ? 'No events yet.' : 'No events are assigned to you yet.' ?></td></tr><?php endif; ?>
    <?php foreach ($events as $ev): ?>
        <tr>
            <td data-label="Event"><a href="<?= url('event.php?id=' . (int)$ev['id']) ?>"><?= e($ev['title']) ?></a></td>
            <td data-label="Date"><?= e(fmt_date($ev['event_date'])) ?></td>
            <td data-label="Category"><?= e($ev['category']) ?></td>
            <td data-label="Registered"><?= (int)$ev['taken'] ?> / <?= (int)$ev['capacity'] ?></td>
            <td data-label="Actions"><div class="actions">
                <a class="btn btn-outline btn-sm" href="<?= url('admin/participants.php?event_id=' . (int)$ev['id']) ?>">Participants</a>
                <a class="btn btn-outline btn-sm" href="<?= url('admin/schedule.php?event_id=' . (int)$ev['id']) ?>">Schedule</a>
                <a class="btn btn-outline btn-sm" href="<?= url('admin/event_form.php?id=' . (int)$ev['id']) ?>">Edit</a>
                <form method="post" action="<?= url('admin/event_delete.php') ?>" data-confirm="Delete this event and all its registrations?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
