-- Tredje textkalla for en extraction_rule: mailets headers (t.ex. Date:,
-- Message-ID:, List-Id:). Generell mekanism pa samma satt som
-- source=subject (se 005_extraction_rule_source.sql) - ger t.ex. mojlighet
-- att extrahera nar mailet skickades till ett eget falt.
--
-- MODIFY ar idempotent - migrate.php kor om samtliga filer varje gang.
SET NAMES utf8mb4;

ALTER TABLE extraction_rules MODIFY COLUMN source ENUM('body','subject','headers') NOT NULL DEFAULT 'body';
