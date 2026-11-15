<?php
require_once __DIR__ . '/includes/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM events WHERE id=?");
$stmt->execute([$id]);
$ev = $stmt->fetch();
if (!$ev) { http_response_code(404); $pageTitle = 'Not found'; require __DIR__ . '/includes/header.php'; echo '<p class="empty">Event not found.</p>'; require __DIR__ . '/includes/footer.php'; exit; }

$taken = seats_taken($id);
$left = max(0, (int)$ev['capacity'] - $taken);
$user = current_user();
$past = $ev['event_date'] < date('Y-m-d');

$myReg = null;
if ($user) {
    $s = db()->prepare("SELECT * FROM registrations WHERE event_id=? AND user_id=?");
    $s->execute([$id, $user['id']]);
    $myReg = $s->fetch();
}
$sched = db()->prepare("SELECT * FROM schedule_items WHERE event_id=? ORDER BY item_time");
$sched->execute([$id]);
$sched = $sched->fetchAll();
$anns = db()->prepare("SELECT * FROM announcements WHERE event_id=? ORDER BY created_at DESC");
$anns->execute([$id]);
$anns = $anns->fetchAll();

$pageTitle = $ev['title'];
$pageDesc = mb_substr($ev['description'], 0, 150);
require __DIR__ . '/includes/header.php';
?>
<article class="event-detail">
    <p><a class="back-link" href="<?= url('events.php') ?>"><?= $navBack ?>All events</a></p>
    <?php if (css_url($ev['image_url']) !== 'none'): ?>
    <div class="detail-img" data-cat="<?= e($ev['category']) ?>">
        <div class="card-img-bg" style="background-image:<?= e(css_url($ev['image_url'])) ?>"></div>
        <span class="badge"><?= e($ev['category']) ?></span>
    </div>
    <?php else: ?>
    <div class="detail-img is-empty" data-cat="<?= e($ev['category']) ?>">
        <span class="badge"><?= e($ev['category']) ?></span>
    </div>
    <?php endif; ?>
    <div class="detail-grid">
        <div class="detail-main">
            <h1><?= e($ev['title']) ?></h1>
            <p class="detail-desc"><?= nl2br(e($ev['description'])) ?></p>

            <h2>Schedule</h2>
            <?php if (!$sched): ?>
                <p class="empty">Detailed schedule will be announced soon.</p>
            <?php else: ?>
                <div class="table-wrap table-schedule">
                <table class="table">
                    <thead><tr><th scope="col">Time</th><th scope="col">Activity</th></tr></thead>
                    <tbody>
                    <?php foreach ($sched as $si): ?>
                        <tr><td><?= e(fmt_time($si['item_time'])) ?></td><td><?= e($si['title']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>

            <?php if ($anns): ?>
                <h2>Announcements</h2>
                <div class="announce-list">
                <?php foreach ($anns as $a): ?>
                    <div class="announce">
                        <p class="announce-date"><?= e(date('d M Y', strtotime($a['created_at']))) ?></p>
                        <div><strong><?= e($a['title']) ?></strong><p><?= e($a['message']) ?></p></div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <aside class="card side">
            <div class="card-body">
                <p class="eyebrow">Event details</p>
                <dl class="facts">
                    <div><dt>Date</dt><dd><?= e(fmt_date($ev['event_date'])) ?></dd></div>
                    <div><dt>Time</dt><dd><?= e(fmt_time($ev['start_time'])) ?><?= $ev['end_time'] ? ' &ndash; ' . e(fmt_time($ev['end_time'])) : '' ?></dd></div>
                    <div><dt>Venue</dt><dd><?= e($ev['venue']) ?></dd></div>
                </dl>
                <div class="progress" aria-hidden="true"><span style="width:<?= $ev['capacity'] ? min(100, round($taken / $ev['capacity'] * 100)) : 0 ?>%"></span></div>
                <p class="seats <?= $left === 0 ? 'full' : '' ?>"><?= $taken ?> / <?= (int)$ev['capacity'] ?> registered &middot; <?= $left === 0 ? 'Full' : $left . ' seats left' ?></p>

                <?php if (!$user): ?>
                    <a class="btn btn-block" href="<?= url('login.php?next=' . urlencode('event.php?id=' . $id)) ?>">Log in to register</a>
                <?php elseif (is_admin_role($user['role'])): ?>
                    <a class="btn btn-block" href="<?= url('admin/participants.php?event_id=' . $id) ?>">View participants</a>
                    <a class="btn btn-outline btn-block" href="<?= url('admin/event_form.php?id=' . $id) ?>">Edit event</a>
                <?php elseif ($past): ?>
                    <p class="status-pill">This event has ended</p>
                <?php elseif ($myReg && $myReg['status'] === 'registered'): ?>
                    <p class="status-pill ok">&#10003; You are registered</p>
                    <form method="post" action="<?= url('register.php') ?>" data-confirm="Cancel your registration?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="event_id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="cancel">
                        <button class="btn btn-outline btn-block" type="submit">Cancel registration</button>
                    </form>
                <?php elseif ($left === 0): ?>
                    <p class="status-pill">Registration full</p>
                <?php else: ?>
                    <form method="post" action="<?= url('register.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="event_id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="register">
                        <button class="btn btn-block" type="submit"><?= $myReg ? 'Register again' : 'Register now' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>
