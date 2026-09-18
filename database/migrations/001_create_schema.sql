-- Mail Parser Gateway - grundschema (MVP)
-- Kor: mysql -u <user> -p <db> < 001_create_schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- systems: avsandarsystem (kan ha flera mailboxes/mallar)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS systems (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(190) NOT NULL,
    description         TEXT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    alert_email         VARCHAR(190) NULL,
    alert_webhook_url   VARCHAR(500) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_systems_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- mailboxes: IMAP-uppgifter per system
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mailboxes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_id           INT UNSIGNED NOT NULL,
    host                VARCHAR(190) NOT NULL,
    port                SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    encryption          ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    username            VARCHAR(190) NOT NULL,
    secret_ciphertext   VARBINARY(1024) NOT NULL,
    secret_nonce        VARBINARY(64) NOT NULL,
    folder              VARCHAR(190) NOT NULL DEFAULT 'INBOX',
    poll_interval_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    last_polled_at      DATETIME NULL,
    last_seen_uid       VARCHAR(64) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mailboxes_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
    KEY idx_mailboxes_system (system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- templates: mallar per system, matchas i prioritetsordning
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS templates (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_id           INT UNSIGNED NOT NULL,
    name                VARCHAR(190) NOT NULL,
    from_pattern        VARCHAR(500) NULL,
    from_match_type     ENUM('exact','contains','regex') NOT NULL DEFAULT 'contains',
    subject_regex       VARCHAR(500) NULL,
    priority            INT NOT NULL DEFAULT 100,
    expected_match_rate DECIMAL(5,2) NOT NULL DEFAULT 0.90,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_templates_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
    KEY idx_templates_system_priority (system_id, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- extraction_rules: en rad per falt som extraheras ur en mall
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS extraction_rules (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id         INT UNSIGNED NOT NULL,
    field_name          VARCHAR(120) NOT NULL,
    field_label         VARCHAR(190) NULL,
    data_type           ENUM('string','number','decimal','date') NOT NULL DEFAULT 'string',
    pattern             VARCHAR(2000) NOT NULL,
    is_required         TINYINT(1) NOT NULL DEFAULT 0,
    sample_source_snippet TEXT NULL,
    sort_order          INT NOT NULL DEFAULT 0,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_by          VARCHAR(190) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rules_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    KEY idx_rules_template (template_id),
    UNIQUE KEY uq_rules_template_field (template_id, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- raw_emails: rått mail, alltid sparat
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS raw_emails (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36) NOT NULL,
    mailbox_id          INT UNSIGNED NULL,
    message_id          VARCHAR(500) NULL,
    imap_uid            VARCHAR(64) NULL,
    from_address        VARCHAR(320) NULL,
    to_address           VARCHAR(320) NULL,
    subject             VARCHAR(998) NULL,
    received_at         DATETIME NULL,
    fetched_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    raw_headers         MEDIUMTEXT NULL,
    raw_body            LONGTEXT NULL,
    normalized_text      MEDIUMTEXT NULL,
    content_hash        CHAR(64) NULL,
    pii_redacted         TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_raw_emails_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_raw_emails_uuid (uuid),
    KEY idx_raw_emails_mailbox (mailbox_id),
    KEY idx_raw_emails_from (from_address(190)),
    KEY idx_raw_emails_received (received_at),
    KEY idx_raw_emails_content_hash (content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- extracted_records: en rad per ingest-forsok av ett mail mot en mall
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS extracted_records (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36) NOT NULL,
    raw_email_id        BIGINT UNSIGNED NOT NULL,
    template_id         INT UNSIGNED NULL,
    status              ENUM('success','partial','failed') NOT NULL,
    match_rate          DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    processed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_records_raw_email FOREIGN KEY (raw_email_id) REFERENCES raw_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_records_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL,
    UNIQUE KEY uq_records_uuid (uuid),
    KEY idx_records_template (template_id),
    KEY idx_records_raw_email (raw_email_id),
    KEY idx_records_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- extracted_fields: EAV - extraherade varden per record
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS extracted_fields (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    extracted_record_id    BIGINT UNSIGNED NOT NULL,
    field_name             VARCHAR(120) NOT NULL,
    field_value             TEXT NULL,
    matched                 TINYINT(1) NOT NULL DEFAULT 0,
    rule_id                 INT UNSIGNED NULL,
    CONSTRAINT fk_fields_record FOREIGN KEY (extracted_record_id) REFERENCES extracted_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_fields_rule FOREIGN KEY (rule_id) REFERENCES extraction_rules(id) ON DELETE SET NULL,
    KEY idx_fields_record (extracted_record_id),
    KEY idx_fields_field_name (field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- api_keys: nycklar for externa konsumenter
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consumer_name       VARCHAR(190) NOT NULL,
    system_id           INT UNSIGNED NOT NULL,
    key_prefix          VARCHAR(16) NOT NULL,
    key_hash            CHAR(64) NOT NULL,
    scopes              JSON NOT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    rate_limit_per_minute INT UNSIGNED NULL,
    last_used_at        DATETIME NULL,
    expires_at          DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_api_keys_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
    UNIQUE KEY uq_api_keys_hash (key_hash),
    KEY idx_api_keys_prefix (key_prefix),
    KEY idx_api_keys_system (system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- api_key_rate_windows: enkel per-minut-rakare for rate limiting
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_key_rate_windows (
    api_key_id          INT UNSIGNED NOT NULL,
    window_start        DATETIME NOT NULL,
    request_count       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (api_key_id, window_start),
    CONSTRAINT fk_rate_windows_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- alerts: format-change- och driftlarm
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type                ENUM('no_template_match','low_match_rate','rule_zero_hits','imap_connection_failed') NOT NULL,
    system_id           INT UNSIGNED NULL,
    template_id         INT UNSIGNED NULL,
    raw_email_id        BIGINT UNSIGNED NULL,
    severity            ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    details_json        JSON NULL,
    status              ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
    notified_at         DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at         DATETIME NULL,
    CONSTRAINT fk_alerts_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
    CONSTRAINT fk_alerts_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_alerts_raw_email FOREIGN KEY (raw_email_id) REFERENCES raw_emails(id) ON DELETE SET NULL,
    KEY idx_alerts_status (status),
    KEY idx_alerts_type_system (type, system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- ingest_log: varje polling-/ingest-handelse
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ingest_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id          INT UNSIGNED NULL,
    raw_email_id        BIGINT UNSIGNED NULL,
    event               ENUM('fetched','template_matched','template_not_matched','rule_engine_ran','imap_error','parse_error') NOT NULL,
    message             TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ingest_log_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE SET NULL,
    CONSTRAINT fk_ingest_log_raw_email FOREIGN KEY (raw_email_id) REFERENCES raw_emails(id) ON DELETE SET NULL,
    KEY idx_ingest_log_mailbox (mailbox_id),
    KEY idx_ingest_log_event (event),
    KEY idx_ingest_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- users: enkel adminvy-inloggning
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(190) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
