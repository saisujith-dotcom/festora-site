<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_organizer();

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$s = db()->prepare("SELECT id, title FROM events WHERE id=?");
$s->execute([$eventId]);
$ev = $s->fetch();
if (!$ev) { http_response_code(404); exit('Event not found.'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare("DELETE FROM schedule_items WHERE id=? AND event_id=?")->execute([(int)$_POST['item_id'], $eventId]);
        flash('Schedule item removed.');
        redirect('admin/schedule.php?event_id=' . $eventId);
    }
    $time = post_str('item_time', 8);
    $title = post_str('title', 150);
    if (!valid_time($time)) $errors[] = 'Enter a valid time.';
    if (mb_strlen($title) < 2) $errors[] = 'Activity title is required.';
    if (!$errors) {
        db()->prepare("INSERT INTO schedule_items (event_id,item_time,title) VALUES (?,?,?)")->execute([$eventId, $time, $title]);
        flash('Schedule item added.');
        redirect('admin/schedule.php?event_id=' . $eventId);
    }
}
$items = db()->prepare("SELECT * FROM schedule_items WHERE event_id=? ORDER BY item_time");
$items->execute([$eventId]);
$items = $items->fetchAll();

$pageTitle = 'Event schedule';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Organiser console</p>
        <h1>Schedule</h1>
        <p class="lede"><?= e($ev['title']) ?></p>
    </div>
    <a class="btn btn-outline" href="<?= url('admin/index.php') ?>">Back</a>
</div>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" class="inline-form" novalidate data-validate>
    <?= csrf_field() ?>
    <input type="hidden" name="event_id" value="<?= $eventId ?>">
    <div><label for="item_time">Time</label><input type="time" id="item_time" name="item_time" required></div>
    <div class="grow"><label for="title">Activity</label><input type="text" id="title" name="title" required minlength="2" maxlength="150"></div>
    <button class="btn" type="submit">Add</button>
</form>
<?php if (!$items): ?><p class="empty">No schedule items yet.</p><?php else: ?>
<div class="table-wrap">
<table class="table table-stack">
    <thead><tr><th scope="col">Time</th><th scope="col">Activity</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
        <tr>
            <td data-label="Time"><?= e(fmt_time($it['item_time'])) ?></td>
            <td data-label="Activity"><?= e($it['title']) ?></td>
            <td data-label="Action">
                <form method="post" data-confirm="Remove this item?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
