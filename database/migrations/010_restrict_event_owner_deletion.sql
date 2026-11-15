ALTER TABLE events
    DROP FOREIGN KEY fk_event_org,
    ADD CONSTRAINT fk_event_owner FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE RESTRICT;
