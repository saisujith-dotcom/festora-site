ALTER TABLE users
    MODIFY COLUMN role ENUM('student','organizer','event_admin','super_admin') NOT NULL DEFAULT 'student',
    ADD COLUMN is_active   TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN created_by  INT UNSIGNED NULL,
    ADD COLUMN disabled_at TIMESTAMP NULL DEFAULT NULL,
    ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
