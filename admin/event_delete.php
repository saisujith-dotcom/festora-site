<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/event-operations.php';
$user = require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('admin/index.php');
csrf_check();
$id = (int)($_POST['id'] ?? 0);

// The permission is checked again under a row lock inside the delete transaction.
$result = delete_event_for_admin(db(), $user, $id);
if ($result === 'forbidden') {
    http_response_code(403);
    exit('Access denied: you are not assigned to this event.');
}
flash($result === 'deleted' ? 'Event deleted.' : 'Event not found.', $result === 'deleted' ? 'success' : 'error');
redirect('admin/index.php');
