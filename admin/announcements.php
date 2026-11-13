<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_organizer();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare("DELETE FROM announcements WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('Announcement deleted.');
        redirect('admin/announcements.php');
    }
    $title = post_str('title', 150);
    $msg = post_str('message', 2000);
    $evId = (int)($_POST['event_id'] ?? 0);
    if (mb_strlen($title) < 3) $errors[] = 'Title must be at least 3 characters.';
    if (mb_strlen($msg) < 5) $errors[] = 'Message must be at least 5 characters.';
    if ($evId) {
        $c = db()->prepare("SELECT COUNT(*) FROM events WHERE id=?");
        $c->execute([$evId]);
        if (!$c->fetchColumn()) $errors[] = 'Selected event does not exist.';
    }
    if (!$errors) {
        db()->prepare("INSERT INTO announcements (event_id,title,message) VALUES (?,?,?)")->execute([$evId ?: null, $title, $msg]);
        flash('Announcement posted.');
        redirect('admin/announcements.php');
    }
}
$events = db()->query("SELECT id, title FROM events ORDER BY event_date DESC")->fetchAll();
$anns = db()->query("SELECT a.*, e.title AS event_title FROM announcements a LEFT JOIN events e ON e.id=a.event_id ORDER BY a.created_at DESC, a.id DESC")->fetchAll();
$pageTitle = 'Announcements';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Organiser console</p>
        <h1>Announcements</h1>
    </div>
</div>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<div class="split">
<div>
<h2>New announcement</h2>
<div class="form-card wide">
    <form method="post" novalidate data-validate>
        <?= csrf_field() ?>
        <label for="title">Title</label>
        <input type="text" id="title" name="title" required minlength="3" maxlength="150">
        <label for="event_id">Related event (optional)</label>
        <select id="event_id" name="event_id">
            <option value="0">General (all students)</option>
            <?php foreach ($events as $ev): ?><option value="<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></option><?php endforeach; ?>
        </select>
        <label for="message">Message</label>
        <textarea id="message" name="message" rows="3" required minlength="5"></textarea>
        <button class="btn" type="submit">Post announcement</button>
    </form>
</div>
</div>

<div>
<h2>Posted</h2>
<?php if (!$anns): ?><p class="empty">No announcements yet.</p><?php endif; ?>
<?php if ($anns): ?><div class="announce-list"><?php endif; ?>
<?php foreach ($anns as $a): ?>
    <div class="announce">
        <p class="announce-date"><?= e(date('d M Y', strtotime($a['created_at']))) ?></p>
        <div>
            <strong><?= e($a['title']) ?></strong>
            <span class="badge badge-soft"><?= e($a['event_title'] ?? 'General') ?></span>
            <p><?= e($a['message']) ?></p>
            <form method="post" data-confirm="Delete this announcement?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
<?php if ($anns): ?></div><?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
