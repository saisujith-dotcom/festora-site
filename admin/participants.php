<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_admin();

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$ev = require_event_manager($user, $eventId);   // 404 if missing, 403 if not assigned to this event

// Event admin removes a participant
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $rid = (int)($_POST['reg_id'] ?? 0);
    db()->prepare("UPDATE registrations SET status='cancelled' WHERE id=? AND event_id=?")->execute([$rid, $eventId]);
    flash('Participant removed from the list.');
    redirect('admin/participants.php?event_id=' . $eventId);
}

$s = db()->prepare("
    SELECT r.id AS reg_id, r.registered_at, u.name, u.email, u.enrollment_no, u.branch
    FROM registrations r JOIN users u ON u.id=r.user_id
    WHERE r.event_id=? AND r.status='registered' ORDER BY r.registered_at");
$s->execute([$eventId]);
$rows = $s->fetchAll();

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants-event-' . $eventId . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Name', 'Email', 'Enrollment No', 'Branch', 'Registered At']);
    foreach ($rows as $i => $r) {
        // Prefix risky leading characters to avoid spreadsheet formula injection
        $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;
        fputcsv($out, [$i + 1, $safe($r['name']), $safe($r['email']), $safe($r['enrollment_no']), $safe($r['branch']), $r['registered_at']]);
    }
    exit;
}

$pageTitle = 'Participants';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Organiser console</p>
        <h1>Participants</h1>
        <p class="lede"><?= e($ev['title']) ?> &middot; <?= count($rows) ?> / <?= (int)$ev['capacity'] ?> registered</p>
    </div>
    <div class="actions">
        <a class="btn btn-outline" href="<?= url('admin/participants.php?event_id=' . $eventId . '&export=csv') ?>">Export CSV</a>
        <a class="btn btn-outline" href="<?= url('admin/index.php') ?>">Back</a>
    </div>
</div>
<?php if (!$rows): ?>
    <p class="empty">No registrations yet.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table table-stack">
    <thead><tr><th scope="col">#</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Enrollment</th><th scope="col">Branch</th><th scope="col">Registered</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td data-label="#"><?= $i + 1 ?></td>
            <td data-label="Name"><?= e($r['name']) ?></td>
            <td data-label="Email"><?= e($r['email']) ?></td>
            <td data-label="Enrollment"><?= e($r['enrollment_no']) ?></td>
            <td data-label="Branch"><?= e($r['branch']) ?></td>
            <td data-label="Registered"><?= e(date('d M Y, g:i A', strtotime($r['registered_at']))) ?></td>
            <td data-label="Action">
                <form method="post" data-confirm="Remove this participant?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="reg_id" value="<?= (int)$r['reg_id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Remove</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
