-- Retention for extraherad data (extracted_records/extracted_fields) och
-- ratt mail (raw_emails). Tva separata dagar-kolumner pa applications
-- eftersom ratt mail ofta ska sparas kortare (PII, se raw_emails.pii_redacted)
-- an den redan bearbetade extraherade datan.
--
-- OBS CASCADE-ordning: extracted_records.raw_email_id har ON DELETE CASCADE
-- mot raw_emails (se 001_create_schema.sql). Raderas ett raw_emails-mail
-- forsvinner darmed ocksa dess extracted_records (+ extracted_fields via
-- fk_fields_record CASCADE) automatiskt - aven om retention_days for
-- extraherad data satts langre an raw_email_retention_days. Detta ar ett
-- medvetet val (se plandiskussion): den effektiva livslangden for
-- extraherad data blir MIN(retention_days, raw_email_retention_days).
-- RetentionService kor darfor tva oberoende cleanup-steg: radering av
-- gamla raw_emails (cascadar), plus en egen radering av extracted_records
-- for fallet att retention_days ar KORTARE an raw_email_retention_days.
--
-- JSON-snapshot-kolumn pa extracted_records (for att undvika N-rads-join
-- vid lasning i API/admin-vy) diskuterades men skjuts upp - det ar en
-- lasoptimering utan koppling till retention-behovet och blandar
-- ansvarsyta om den laggs i samma migration. Tas upp separat vid behov.
--
-- Idempotent i samma stil som tidigare migrationer - migrate.php kor om
-- samtliga filer varje gang.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. applications.retention_days - extraherad data (extracted_records/
--    extracted_fields). NULL = spara for alltid.
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'retention_days'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE applications ADD COLUMN retention_days INT UNSIGNED NULL DEFAULT 90 AFTER is_active',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. applications.raw_email_retention_days - ratt mail (raw_emails).
--    NULL = spara for alltid. Separat fran retention_days sa ratt mail
--    (PII) kan ges kortare livslangd an den extraherade datan.
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'raw_email_retention_days'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE applications ADD COLUMN raw_email_retention_days INT UNSIGNED NULL DEFAULT 90 AFTER retention_days',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. extracted_records: composite-index for cleanup-fragan
--    (WHERE template_id IN (...) AND processed_at < ?)
-- ---------------------------------------------------------------------
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'extracted_records' AND INDEX_NAME = 'idx_records_template_processed'
);
SET @sql = IF(
    @idx_exists = 0,
    'ALTER TABLE extracted_records ADD INDEX idx_records_template_processed (template_id, processed_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. raw_emails: composite-index for cleanup-fragan
--    (WHERE mailbox_id IN (...) AND fetched_at < ?). fetched_at anvands
--    istallet for received_at eftersom received_at kan vara NULL (satts
--    fran mailets Date-header) medan fetched_at alltid ar NOT NULL
--    DEFAULT CURRENT_TIMESTAMP - garanterat satt for varje rad.
-- ---------------------------------------------------------------------
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_emails' AND INDEX_NAME = 'idx_raw_emails_mailbox_fetched'
);
SET @sql = IF(
    @idx_exists = 0,
    'ALTER TABLE raw_emails ADD INDEX idx_raw_emails_mailbox_fetched (mailbox_id, fetched_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
