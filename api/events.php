<?php
// JSON endpoint used by AJAX search/filter on events.php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/event-search.php';
header('Content-Type: application/json; charset=utf-8');

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$cat = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$showPast = ($_GET['past'] ?? '') === '1';

$rows = search_events(db(), $q, $cat, $showPast);

foreach ($rows as &$r) {
    $r['date_label'] = fmt_date($r['event_date']);
    $r['time_label'] = fmt_time($r['start_time']);
    $r['seats_left'] = max(0, (int)$r['capacity'] - (int)$r['taken']);
    $r['url'] = url('event.php?id=' . (int)$r['id']);
}
echo json_encode(['count' => count($rows), 'events' => $rows]);
