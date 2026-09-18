-- Knyter varje API-nyckel till exakt ett system. Utan detta kunde en nyckel
-- med t.ex. scope "records:read" lasa records for ALLA system - ingen
-- multi-tenant-isolering. Se ApiKeyAuthMiddleware/RecordsController/MetaController.
SET NAMES utf8mb4;

-- Idempotent ALTER: kor bara om kolumnen inte redan finns (migrate.php kor
-- om samtliga filer varje gang, och information_schema-koll skyddar mot
-- "Duplicate column"-fel vid omkorning).
--
-- OBS: 004_rename_systems_to_applications.sql byter senare namn pa denna
-- kolumn till application_id (system_id ar forlegat och ska inte
-- anvandas langre). Om den omdopningen redan kort maste detta steg
-- hoppa over helt - annars skulle en ny (tom) system_id-kolumn skapas
-- vid sidan av den redan omdopta application_id, se DEALLOCATE-historik
-- i 004 for detaljer kring bakgrunden.
SET @application_id_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND COLUMN_NAME = 'application_id'
);
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND COLUMN_NAME = 'system_id'
);

SET @sql = IF(
    @col_exists = 0 AND @application_id_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN system_id INT UNSIGNED NULL AFTER consumer_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Befintliga nycklar utan system: knyt till lagsta system-id som en
-- rimlig standard sa NOT NULL-konverteringen nedan inte misslyckas. I det
-- har fallet finns bara en befintlig testnyckel (id 2, "api-test") som
-- kopplas till system "Boappa". Hoppas over nar application_id redan finns
-- (se OBS ovan) - m√•ste byggas dynamiskt (PREPARE/EXECUTE) sa MySQL inte
-- forsoker parsa "system_id" mot tabellen nar kolumnen redan bytt namn,
-- vilket annars kraschar redan vid parse-tid trots villkoret.
SET @sql = IF(
    @col_exists > 0 AND @application_id_exists = 0,
    'UPDATE api_keys SET system_id = (SELECT MIN(id) FROM systems) WHERE system_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND CONSTRAINT_NAME = 'fk_api_keys_system'
);

SET @sql = IF(
    @fk_exists = 0 AND @application_id_exists = 0,
    'ALTER TABLE api_keys
        MODIFY COLUMN system_id INT UNSIGNED NOT NULL,
        ADD CONSTRAINT fk_api_keys_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
        ADD KEY idx_api_keys_system (system_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
