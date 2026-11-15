CREATE TABLE event_admin_assignments (
    event_id    INT UNSIGNED NOT NULL,
    admin_id    INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, admin_id),
    INDEX idx_assignment_admin (admin_id),
    CONSTRAINT fk_assign_event FOREIGN KEY (event_id)    REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_admin FOREIGN KEY (admin_id)    REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_assign_by    FOREIGN KEY (assigned_by) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB;
