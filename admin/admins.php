<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-management.php';
$user = require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $targetId = (int)($_POST['id'] ?? 0);
    if (!in_array($action, ['deactivate', 'reactivate', 'delete'], true)) {
        http_response_code(400);
        exit('Unknown action.');
    }
    // This console manages Event Admin accounts only; Super Admins are provisioned from the command line.
    $role = db()->prepare('SELECT role FROM users WHERE id=?');
    $role->execute([$targetId]);
    if ($role->fetchColumn() !== 'event_admin') {
        flash(...admin_status_message('not_event_admin'));
    } elseif ($action === 'delete') {
        $transferToId = (int)($_POST['to_id'] ?? 0);
        $result = admin_delete(
            db(),
            (int)$user['id'],
            $targetId,
            $transferToId > 0 ? $transferToId : null
        );
        flash(...admin_status_message($result['status']));
    } else {
        $result = admin_set_active(db(), (int)$user['id'], $targetId, $action === 'reactivate');
        flash(...admin_status_message($result['status']));
    }
    redirect('admin/admins.php');
}

$admins = db()->query("
    SELECT u.id, u.name, u.email, u.is_active, u.created_at,
           (SELECT COUNT(*) FROM event_admin_assignments a WHERE a.admin_id=u.id) AS assigned,
           (SELECT COUNT(*) FROM events e WHERE e.organizer_id=u.id) AS owned
    FROM users u WHERE u.role='event_admin' ORDER BY u.is_active DESC, u.name, u.id")->fetchAll();

$deleteAdmin = null;
$deleteEvents = 0;
$deleteRegistrations = 0;
$transferTargets = [];
$deleteId = (int)($_GET['delete'] ?? 0);
if ($deleteId > 0) {
$target = db()->prepare('SELECT id, name, email, role FROM users WHERE id=?');
$target->execute([$deleteId]);
$deleteAdmin = $target->fetch();
if (!$deleteAdmin) {
    http_response_code(404);
    exit('Account not found.');
}
if ($deleteAdmin['role'] !== 'event_admin') {
    http_response_code(403);
    exit('Only Event Admin accounts can be managed here.');
}
$eventCount = db()->prepare(
    'SELECT COUNT(DISTINCT e.id)
     FROM events e
     LEFT JOIN event_admin_assignments a ON a.event_id=e.id
     WHERE e.organizer_id=? OR a.admin_id=?'
);
$eventCount->execute([$deleteId, $deleteId]);
$deleteEvents = (int)$eventCount->fetchColumn();
$registrationCount = db()->prepare('SELECT COUNT(*) FROM registrations WHERE user_id=?');
$registrationCount->execute([$deleteId]);
$deleteRegistrations = (int)$registrationCount->fetchColumn();
if ($deleteEvents > 0) {
    $transferTargets = db()->prepare(
        "SELECT id, name, role FROM users
         WHERE role IN ('event_admin','super_admin') AND is_active=1 AND id<>?
         ORDER BY role DESC, name, id"
    );
    $transferTargets->execute([$deleteId]);
    $transferTargets = $transferTargets->fetchAll();
}
}

$pageTitle = 'Event Admins';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Super Admin console</p>
        <h1>Event Admins</h1>
        <p class="lede">Create Event Admin accounts, choose which events each one manages, and deactivate or remove them safely.</p>
    </div>
    <div class="actions">
        <a class="btn" href="<?= url('admin/admin_form.php') ?>">+ New Event Admin</a>
        <a class="btn btn-outline" href="<?= url('admin/index.php') ?>">Back</a>
    </div>
</div>

<?php if ($deleteAdmin): ?>
<section class="form-card wide" aria-labelledby="delete-admin-heading">
    <h2 id="delete-admin-heading">Delete <?= e($deleteAdmin['name']) ?>?</h2>
    <p class="lede"><?= e($deleteAdmin['email']) ?></p>
    <?php if ($deleteRegistrations > 0): ?>
        <div class="alert alert-error" role="alert">
            This account has <?= $deleteRegistrations ?> registration(s) and cannot be deleted. Deactivate it instead.
        </div>
    <?php else: ?>
        <p class="lede">The account will be removed. Events and registrations will not be deleted.</p>
        <?php if ($deleteEvents > 0): ?>
            <p class="lede"><?= $deleteEvents ?> event(s) are assigned to or owned by this administrator.
                Transfer their event access before deleting the account.</p>
            <?php if (!$transferTargets): ?>
                <div class="alert alert-error" role="alert">No active administrator is available to receive these events.</div>
            <?php else: ?>
                <form method="post" data-confirm="Delete this account and transfer its event access to the selected administrator? Event and registration data will be preserved.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $deleteId ?>">
                    <input type="hidden" name="action" value="delete">
                    <label for="transfer-delete-to">Transfer events to</label>
                    <select id="transfer-delete-to" name="to_id" required>
                        <?php foreach ($transferTargets as $target): ?>
                            <option value="<?= (int)$target['id'] ?>"><?= e($target['name']) ?> (<?= $target['role'] === 'super_admin' ? 'Super Admin' : 'Event Admin' ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="actions">
                        <button class="btn btn-danger" type="submit">Transfer events and delete account</button>
                        <a class="btn btn-outline" href="<?= url('admin/admins.php') ?>">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <form method="post" data-confirm="Delete this account? The account cannot be restored.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $deleteId ?>">
                <input type="hidden" name="action" value="delete">
                <div class="actions">
                    <button class="btn btn-danger" type="submit">Delete account</button>
                    <a class="btn btn-outline" href="<?= url('admin/admins.php') ?>">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="table-wrap">
<table class="table table-stack">
    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Events</th><th scope="col">Created</th><th scope="col">Actions</th></tr></thead>
    <tbody>
    <?php if (!$admins): ?><tr><td colspan="6" class="empty">No Event Admins yet. Create the first one.</td></tr><?php endif; ?>
    <?php foreach ($admins as $a): $active = (int)$a['is_active'] === 1; ?>
        <tr>
            <td data-label="Name"><?= e($a['name']) ?></td>
            <td data-label="Email"><?= e($a['email']) ?></td>
            <td data-label="Status"><span class="status-pill <?= $active ? 'ok' : '' ?>"><?= $active ? 'Active' : 'Deactivated' ?></span></td>
            <td data-label="Events"><?= (int)$a['assigned'] ?> assigned &middot; <?= (int)$a['owned'] ?> owned</td>
            <td data-label="Created"><?= e(date('d M Y', strtotime($a['created_at']))) ?></td>
            <td data-label="Actions"><div class="actions">
                <a class="btn btn-outline btn-sm" href="<?= url('admin/admin_events.php?id=' . (int)$a['id']) ?>">Events</a>
                <a class="btn btn-outline btn-sm" href="<?= url('admin/admin_form.php?id=' . (int)$a['id']) ?>">Edit</a>
                <form method="post" data-confirm="<?= $active ? 'Deactivate this account? They will be signed out and unable to log in.' : 'Reactivate this account?' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'reactivate' ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= $active ? 'Deactivate' : 'Reactivate' ?></button>
                </form>
                <a class="btn btn-danger btn-sm" href="<?= url('admin/admins.php?delete=' . (int)$a['id']) ?>">Delete</a>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="empty">Super Admin accounts are provisioned from the command line and cannot be changed here.</p>
<?php require __DIR__ . '/../includes/footer.php'; ?>
