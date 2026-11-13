<?php

/** @return list<array<string,mixed>> */
function search_events(PDO $pdo, string $query, string $category, bool $showPast): array {
    $sql = "SELECT e.id, e.title, e.category, e.venue, e.event_date, e.start_time, e.capacity, e.image_url,
            (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='registered') AS taken
            FROM events e WHERE 1=1";
    $params = [];
    if (!$showPast) {
        $sql .= ' AND e.event_date >= CURDATE()';
    }
    if ($query !== '') {
        $sql .= ' AND (e.title LIKE ? OR e.description LIKE ? OR e.venue LIKE ?)';
        $like = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        array_push($params, $like, $like, $like);
    }
    if ($category !== '' && in_array($category, CATEGORIES, true)) {
        $sql .= ' AND e.category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY e.event_date, e.start_time LIMIT 100';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}
