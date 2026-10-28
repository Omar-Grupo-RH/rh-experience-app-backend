
ALTER TABLE passenger_documents
    MODIFY COLUMN front_path VARCHAR(500) NULL,
    MODIFY COLUMN back_path VARCHAR(500) NULL,
    ADD COLUMN rejection_notified_at DATETIME NULL,
    ADD COLUMN rejection_email_error VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS identity_document_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    passenger_document_id BIGINT UNSIGNED NOT NULL,
    ticket_passenger_id INT NOT NULL,
    admin_user_id INT NOT NULL,
    event_type ENUM(
        'verified',
        'rejected',
        'replaced',
        'notification_sent',
        'notification_failed'
    ) NOT NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_identity_events_passenger (ticket_passenger_id),
    KEY idx_identity_events_document (passenger_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
