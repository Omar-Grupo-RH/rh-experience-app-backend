
-- RH EXPERIENCE
-- Migración: identificaciones y responsivas individuales
-- Ejecutar una sola vez sobre rh_experience.
-- Requiere MySQL 8+ y tablas existentes: ticket_passengers.

CREATE TABLE passenger_document_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_uuid CHAR(36) NOT NULL,
    passenger_uuid CHAR(36) NOT NULL,
    ine_front_path VARCHAR(500) DEFAULT NULL,
    ine_back_path VARCHAR(500) DEFAULT NULL,
    waiver_data JSON DEFAULT NULL,
    signature_path VARCHAR(500) DEFAULT NULL,
    status ENUM(
        'pending',
        'documents_uploaded',
        'signed',
        'linked',
        'expired'
    ) NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_passenger (draft_uuid, passenger_uuid),
    KEY idx_draft_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE passenger_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_passenger_id INT NOT NULL,
    document_type ENUM('ine') NOT NULL DEFAULT 'ine',
    front_path VARCHAR(500) NOT NULL,
    back_path VARCHAR(500) NOT NULL,
    front_sha256 CHAR(64) DEFAULT NULL,
    back_sha256 CHAR(64) DEFAULT NULL,
    verification_status ENUM(
        'pending',
        'verified',
        'rejected'
    ) NOT NULL DEFAULT 'pending',
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_passenger_document (
        ticket_passenger_id,
        document_type
    ),
    CONSTRAINT fk_document_passenger
        FOREIGN KEY (ticket_passenger_id)
        REFERENCES ticket_passengers(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE waiver_templates (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    version VARCHAR(30) NOT NULL,
    template_path VARCHAR(500) NOT NULL,
    fields_json JSON DEFAULT NULL,
    document_sha256 CHAR(64) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_template_version (name, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE passenger_waivers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_passenger_id INT NOT NULL,
    template_id INT NOT NULL,
    completed_data JSON NOT NULL,
    signed_pdf_path VARCHAR(500) NOT NULL,
    signed_pdf_sha256 CHAR(64) NOT NULL,
    signature_method VARCHAR(40) NOT NULL DEFAULT 'drawn',
    consent_accepted_at DATETIME NOT NULL,
    signed_at DATETIME NOT NULL,
    signer_email VARCHAR(190) DEFAULT NULL,
    verification_method VARCHAR(50) DEFAULT NULL,
    verification_reference VARCHAR(150) DEFAULT NULL,
    status ENUM('signed', 'voided')
        NOT NULL DEFAULT 'signed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_passenger_waiver (ticket_passenger_id),
    CONSTRAINT fk_waiver_passenger
        FOREIGN KEY (ticket_passenger_id)
        REFERENCES ticket_passengers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_waiver_template
        FOREIGN KEY (template_id)
        REFERENCES waiver_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
