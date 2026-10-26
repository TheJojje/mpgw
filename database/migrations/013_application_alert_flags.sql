-- Per-applikation reglage for vilka automatiska larm som ska skapas:
--   alert_on_partial         - mail matchade mallen men inte alla falt (MailboxPoller)
--   alert_on_thread_anomaly  - avvikelse i en trad (ThreadService)
-- Bada pa som standard (samma beteende som innan reglagen fanns).
--
-- Idempotent i samma stil som tidigare migrationer - migrate.php kor om
-- samtliga filer varje gang.
SET NAMES utf8mb4;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'alert_on_partial'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE applications
        ADD COLUMN alert_on_partial TINYINT(1) NOT NULL DEFAULT 1,
        ADD COLUMN alert_on_thread_anomaly TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
