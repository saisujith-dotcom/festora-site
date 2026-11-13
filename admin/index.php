<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_organizer();
$pageTitle = 'Organizer Dashboard';

$stats = db()->query("
    SELECT (SELECT COUNT(*) FROM events) AS events,
           (SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()) AS upcoming,
           (SELECT COUNT(*) FROM registrations WHERE status='registered') AS regs,
           (SELECT COUNT(*) FROM users WHERE role='student') AS students")->fetch();

$events = db()->query("
    SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='registered') AS taken
    FROM events e ORDER BY e.event_date DESC, e.start_time")->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Organiser console</p>
        <h1>Organizer Dashboard</h1>
    </div>
    <a class="btn" href="<?= url('admin/event_form.php') ?>">+ New event</a>
</div>

<div class="stats">
    <div class="stat"><strong><?= (int)$stats['events'] ?></strong><span>Total events</span></div>
    <div class="stat"><strong><?= (int)$stats['upcoming'] ?></strong><span>Upcoming</span></div>
    <div class="stat"><strong><?= (int)$stats['regs'] ?></strong><span>Active registrations</span></div>
    <div class="stat"><strong><?= (int)$stats['students'] ?></strong><span>Students</span></div>
</div>

<div class="table-wrap">
<table class="table table-stack">
    <thead><tr><th scope="col">Event</th><th scope="col">Date</th><th scope="col">Category</th><th scope="col">Registered</th><th scope="col">Actions</th></tr></thead>
    <tbody>
    <?php if (!$events): ?><tr><td colspan="5" class="empty">No events yet.</td></tr><?php endif; ?>
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
