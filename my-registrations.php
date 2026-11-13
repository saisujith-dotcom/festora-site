<?php
$pageTitle = 'My Registrations';
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$s = db()->prepare("
    SELECT r.status, r.registered_at, e.*
    FROM registrations r JOIN events e ON e.id=r.event_id
    WHERE r.user_id=? ORDER BY e.event_date DESC, e.start_time");
$s->execute([$user['id']]);
$rows = $s->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Student</p>
        <h1>My Registrations</h1>
    </div>
    <a class="btn btn-outline" href="<?= url('events.php') ?>">Browse events</a>
</div>
<?php if (!$rows): ?>
    <p class="empty">You have not registered for any events yet. <a href="<?= url('events.php') ?>">Browse events</a>.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table table-stack">
    <thead><tr><th scope="col">Event</th><th scope="col">Date</th><th scope="col">Venue</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $past = $r['event_date'] < date('Y-m-d'); ?>
        <tr>
            <td data-label="Event"><a href="<?= url('event.php?id=' . (int)$r['id']) ?>"><?= e($r['title']) ?></a></td>
            <td data-label="Date"><?= e(fmt_date($r['event_date'])) ?>, <?= e(fmt_time($r['start_time'])) ?></td>
            <td data-label="Venue"><?= e($r['venue']) ?></td>
            <td data-label="Status"><span class="status-pill <?= $r['status'] === 'registered' ? 'ok' : '' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td data-label="Action">
                <?php if ($r['status'] === 'registered' && !$past): ?>
                <form method="post" action="<?= url('register.php') ?>" data-confirm="Cancel your registration?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="event_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button class="btn btn-outline btn-sm" type="submit">Cancel</button>
                </form>
                <?php else: ?>&mdash;<?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
