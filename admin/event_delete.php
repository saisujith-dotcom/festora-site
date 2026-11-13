<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_organizer();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('admin/index.php');
csrf_check();
$id = (int)($_POST['id'] ?? 0);
$s = db()->prepare("DELETE FROM events WHERE id=?");
$s->execute([$id]);
flash($s->rowCount() ? 'Event deleted.' : 'Event not found.', $s->rowCount() ? 'success' : 'error');
redirect('admin/index.php');
