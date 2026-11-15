INSERT IGNORE INTO event_admin_assignments (event_id, admin_id, assigned_by)
SELECT e.id, e.organizer_id, NULL
FROM events e
JOIN users u ON u.id = e.organizer_id
WHERE u.role = 'organizer';
