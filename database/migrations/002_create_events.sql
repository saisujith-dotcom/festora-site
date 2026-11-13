CREATE TABLE events (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(150) NOT NULL,
    description  TEXT NOT NULL,
    category     ENUM('Technical','Cultural','Sports','Workshop','Seminar','Other') NOT NULL DEFAULT 'Other',
    venue        VARCHAR(150) NOT NULL,
    event_date   DATE NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NULL,
    capacity     INT UNSIGNED NOT NULL DEFAULT 50,
    image_url    VARCHAR(300) NULL,
    organizer_id INT UNSIGNED NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (event_date),
    INDEX idx_category (category),
    CONSTRAINT fk_event_org FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
