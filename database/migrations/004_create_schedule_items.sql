CREATE TABLE schedule_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id   INT UNSIGNED NOT NULL,
    item_time  TIME NOT NULL,
    title      VARCHAR(150) NOT NULL,
    CONSTRAINT fk_sched_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;
