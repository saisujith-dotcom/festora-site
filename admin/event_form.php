<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/event-operations.php';
$user = require_organizer();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$ev = ['title' => '', 'description' => '', 'category' => 'Technical', 'venue' => '', 'event_date' => '',
       'start_time' => '', 'end_time' => '', 'capacity' => 50, 'image_url' => ''];
if ($id) {
    $s = db()->prepare("SELECT * FROM events WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) { http_response_code(404); exit('Event not found.'); }
    $ev = array_merge($ev, $row);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ev['title'] = post_str('title', 150);
    $ev['description'] = post_str('description', 5000);
    $ev['category'] = post_str('category', 20);
    $ev['venue'] = post_str('venue', 150);
    $ev['event_date'] = post_str('event_date', 10);
    $ev['start_time'] = post_str('start_time', 8);
    $ev['end_time'] = post_str('end_time', 8);
    $ev['capacity'] = (int)($_POST['capacity'] ?? 0);
    $ev['image_url'] = post_str('image_url', 300);

    if (mb_strlen($ev['title']) < 3) $errors[] = 'Title must be at least 3 characters.';
    if (mb_strlen($ev['description']) < 10) $errors[] = 'Description must be at least 10 characters.';
    if (!in_array($ev['category'], CATEGORIES, true)) $errors[] = 'Choose a valid category.';
    if ($ev['venue'] === '') $errors[] = 'Venue is required.';
    $d = DateTime::createFromFormat('Y-m-d', $ev['event_date']);
    if (!$d || $d->format('Y-m-d') !== $ev['event_date']) $errors[] = 'Enter a valid date.';
    if (!valid_time($ev['start_time'])) $errors[] = 'Enter a valid start time.';
    if ($ev['end_time'] !== '' && !valid_time($ev['end_time'])) $errors[] = 'Enter a valid end time.';
    if ($ev['end_time'] !== '' && $ev['start_time'] !== '' && $ev['end_time'] <= $ev['start_time']) $errors[] = 'End time must be after start time.';
    if ($ev['capacity'] < 1 || $ev['capacity'] > 100000) $errors[] = 'Capacity must be between 1 and 100000.';
    if ($ev['image_url'] !== '' && !valid_image_url($ev['image_url'])) $errors[] = 'Image URL must be a valid http:// or https:// link with no spaces, quotes, parentheses or backslashes (encode them, e.g. %20).';
    if (!$errors) {
        $end = $ev['end_time'] !== '' ? $ev['end_time'] : null;
        $img = $ev['image_url'] !== '' ? $ev['image_url'] : null;
        try {
            if ($id) {
                $updated = update_event_with_capacity(db(), $id, [
                    'title' => $ev['title'],
                    'description' => $ev['description'],
                    'category' => $ev['category'],
                    'venue' => $ev['venue'],
                    'event_date' => $ev['event_date'],
                    'start_time' => $ev['start_time'],
                    'end_time' => $end,
                    'capacity' => $ev['capacity'],
                    'image_url' => $img,
                ]);
                if (!$updated) {
                    $errors[] = 'Capacity cannot be lower than the number of active registrations.';
                } else {
                    flash('Event updated.');
                    redirect('admin/index.php');
                }
            } else {
                db()->prepare("INSERT INTO events (title,description,category,venue,event_date,start_time,end_time,capacity,image_url,organizer_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$ev['title'], $ev['description'], $ev['category'], $ev['venue'], $ev['event_date'], $ev['start_time'], $end, $ev['capacity'], $img, $user['id']]);
                flash('Event created.');
                redirect('admin/index.php');
            }
        } catch (Throwable $error) {
            festora_log_error('event_save', $error);
            $errors[] = 'Event could not be saved. Please try again.';
        }
    }
}
$pageTitle = $id ? 'Edit event' : 'New event';
require __DIR__ . '/../includes/header.php';
$t = fn($v) => $v ? substr((string)$v, 0, 5) : '';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Organiser console</p>
        <h1><?= $id ? 'Edit event' : 'Create event' ?></h1>
    </div>
    <a class="btn btn-outline" href="<?= url('admin/index.php') ?>">Back</a>
</div>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<div class="form-card wide">
    <form method="post" novalidate data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" required minlength="3" maxlength="150" value="<?= e($ev['title']) ?>">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="5" required minlength="10"><?= e($ev['description']) ?></textarea>
        <div class="row">
            <div>
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <?php foreach (CATEGORIES as $c): ?><option <?= $ev['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="capacity">Capacity</label>
                <input type="number" id="capacity" name="capacity" required min="1" max="100000" value="<?= (int)$ev['capacity'] ?>">
            </div>
        </div>
        <label for="venue">Venue</label>
        <input type="text" id="venue" name="venue" required maxlength="150" value="<?= e($ev['venue']) ?>">
        <div class="row three">
            <div><label for="event_date">Date</label><input type="date" id="event_date" name="event_date" required value="<?= e($ev['event_date']) ?>"></div>
            <div><label for="start_time">Start time</label><input type="time" id="start_time" name="start_time" required value="<?= e($t($ev['start_time'])) ?>"></div>
            <div><label for="end_time">End time (optional)</label><input type="time" id="end_time" name="end_time" value="<?= e($t($ev['end_time'])) ?>"></div>
        </div>
        <label for="image_url">Image URL (optional)</label>
        <input type="url" id="image_url" name="image_url" placeholder="https://..." value="<?= e($ev['image_url']) ?>">
        <div class="actions">
            <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Create event' ?></button>
            <a class="btn btn-outline" href="<?= url('admin/index.php') ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
