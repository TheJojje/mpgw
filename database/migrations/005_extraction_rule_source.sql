-- Later en extraction_rule ange om den ska koras mot mailets amnesrad
-- eller brodtext, istallet for att implicit alltid anta brodtext.
--
-- Generell mekanism - infor inget begrepp om VAD ett mail betyder (ingen
-- "bokning"/"avbokning" nagonstans i schemat), bara VILKEN textkalla en
-- given regel ska lasa fran. En admin kan t.ex. skapa ett falt
-- "event_type" med source=subject for att extrahera nagot ur rubriken,
-- precis som befintliga falt extraheras ur brodtexten.
--
-- Idempotent i samma stil som tidigare migrationer - migrate.php kor om
-- samtliga filer varje gang.
SET NAMES utf8mb4;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'extraction_rules' AND COLUMN_NAME = 'source'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE extraction_rules ADD COLUMN source ENUM('body','subject') NOT NULL DEFAULT 'body' AFTER field_name",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
