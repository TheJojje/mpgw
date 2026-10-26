-- Tradar: koppla ihop mail som hor till samma "arende" (t.ex. en bokning
-- och dess avbokning) sa att konsumenter slipper para ihop dem sjalva.
--
-- Generell mekanism - inget begrepp om VAD ett mail betyder. Per
-- applikation konfigureras:
--   thread_key_fields   kommaseparerade faltnamn som tillsammans identifierar
--                       "samma arende" (t.ex. guest_name,apartment_number,...)
--   thread_event_field  faltet vars varde avgor om en handelse oppnar eller
--                       stanger en trad (t.ex. event_type)
--   thread_open_values  kommaseparerade varden som OPPNAR en trad
--   thread_close_values kommaseparerade varden som STANGER en trad
-- Jamforelsen ar skiftlagesoberoende. Lamnas konfigurationen tom
-- anvands inga tradar.
--
-- Per extracted_record sparas: thread_key (hash av nyckelfalten),
-- thread_no (nte tradlivscykeln for nyckeln), thread_sequence (ordning i
-- tradden), thread_event (open/close), thread_state (tradens nuvarande
-- lage) och thread_anomaly (t.ex. close_without_open).
--
-- Idempotent i samma stil som tidigare migrationer - migrate.php kor om
-- samtliga filer varje gang.
SET NAMES utf8mb4;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'thread_key_fields'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE applications
        ADD COLUMN thread_key_fields TEXT NULL,
        ADD COLUMN thread_event_field VARCHAR(120) NULL,
        ADD COLUMN thread_open_values TEXT NULL,
        ADD COLUMN thread_close_values TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'extracted_records' AND COLUMN_NAME = 'thread_key'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE extracted_records
        ADD COLUMN thread_key CHAR(64) NULL,
        ADD COLUMN thread_no INT UNSIGNED NULL,
        ADD COLUMN thread_sequence INT UNSIGNED NULL,
        ADD COLUMN thread_event ENUM('open','close') NULL,
        ADD COLUMN thread_state ENUM('open','closed') NULL,
        ADD COLUMN thread_anomaly VARCHAR(40) NULL,
        ADD KEY idx_records_thread_key (thread_key)",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ny larmtyp for inkonsekventa tradar. MODIFY ar idempotent.
ALTER TABLE alerts MODIFY COLUMN type
    ENUM('no_template_match','low_match_rate','rule_zero_hits','imap_connection_failed','thread_anomaly') NOT NULL;
