-- Byter namn pa entiteten "system" till "applikation" (application) rakt
-- igenom: tabellen systems -> applications, kolumnen system_id ->
-- application_id pa alla beroende tabeller, samt FK-/index-namn och
-- RBAC-rattighetsslugs. Rors INTE users_roles.is_system (skyddar inbyggda
-- roller mot radering) eller log_events.source's 'system'-enum-varde
-- (menar "systemet sjalvt", t.ex. cron - inte entiteten).
--
-- Idempotent i samma stil som 002_api_keys_system_id.sql - migrate.php
-- kor om samtliga filer varje gang.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Tabellen systems -> applications
--
-- OBS: 001_create_schema.sql aterskapar alltid en TOM systems-tabell
-- (CREATE TABLE IF NOT EXISTS) eftersom migrate.php kor om SAMTLIGA
-- filer varje gang - en ren "finns systems?"-koll racker darfor inte,
-- den skulle forsoka RENAME pa varje efterfoljande korning aven efter
-- att applications redan finns. Kravs darfor ocksa att applications
-- INTE redan finns innan RENAME forsoks.
-- ---------------------------------------------------------------------
SET @applications_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications'
);
SET @table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'systems'
);

SET @sql = IF(
    @table_exists > 0 AND @applications_exists = 0,
    'RENAME TABLE systems TO applications',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Nar applications redan finns aterskapar 001 fortfarande en tom
-- systems-tabell varje korning (CREATE TABLE IF NOT EXISTS) - den ar
-- ofarlig dodvikt (inga rader, inget refererar den for FK:ar som redan
-- byttes till applications forsta gangen RENAME korde) och lamnas darfor
-- kvar ist√§llet for att droppas: mailboxes/templates/alerts/api_keys
-- har via detta skripts ovriga steg redan sina FK:ar mot applications,
-- sa nagon enstaka DROP-forsok pa systems riskerar bara att krascha om
-- ordningen nagonsin blir en annan.

SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND CONSTRAINT_NAME = 'uq_systems_name'
);

SET @sql = IF(
    @constraint_exists > 0,
    'ALTER TABLE applications RENAME INDEX uq_systems_name TO uq_applications_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. mailboxes.system_id -> application_id
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailboxes' AND COLUMN_NAME = 'system_id'
);

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE mailboxes DROP FOREIGN KEY fk_mailboxes_system',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE mailboxes CHANGE COLUMN system_id application_id INT UNSIGNED NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE mailboxes
        ADD CONSTRAINT fk_mailboxes_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailboxes' AND INDEX_NAME = 'idx_mailboxes_system'
);

SET @sql = IF(
    @idx_exists > 0,
    'ALTER TABLE mailboxes RENAME INDEX idx_mailboxes_system TO idx_mailboxes_application',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. templates.system_id -> application_id
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'templates' AND COLUMN_NAME = 'system_id'
);

SET @sql = IF(@col_exists > 0, 'ALTER TABLE templates DROP FOREIGN KEY fk_templates_system', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE templates CHANGE COLUMN system_id application_id INT UNSIGNED NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE templates
        ADD CONSTRAINT fk_templates_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'templates' AND INDEX_NAME = 'idx_templates_system_priority'
);

SET @sql = IF(
    @idx_exists > 0,
    'ALTER TABLE templates RENAME INDEX idx_templates_system_priority TO idx_templates_application_priority',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. api_keys.system_id -> application_id
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND COLUMN_NAME = 'system_id'
);

SET @sql = IF(@col_exists > 0, 'ALTER TABLE api_keys DROP FOREIGN KEY fk_api_keys_system', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE api_keys CHANGE COLUMN system_id application_id INT UNSIGNED NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE api_keys
        ADD CONSTRAINT fk_api_keys_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND INDEX_NAME = 'idx_api_keys_system'
);

SET @sql = IF(
    @idx_exists > 0,
    'ALTER TABLE api_keys RENAME INDEX idx_api_keys_system TO idx_api_keys_application',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 5. alerts.system_id -> application_id
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND COLUMN_NAME = 'system_id'
);

SET @sql = IF(@col_exists > 0, 'ALTER TABLE alerts DROP FOREIGN KEY fk_alerts_system', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE alerts CHANGE COLUMN system_id application_id INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE alerts
        ADD CONSTRAINT fk_alerts_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND INDEX_NAME = 'idx_alerts_type_system'
);

SET @sql = IF(
    @idx_exists > 0,
    'ALTER TABLE alerts RENAME INDEX idx_alerts_type_system TO idx_alerts_type_application',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 6. RBAC: byt namn pa rattighetsslugs systems.view/systems.manage ->
--    applications.view/applications.manage. UPDATE (inte re-seed) sa
--    befintliga users_role_permissions-kopplingar (som pekar pa
--    permission_id, inte slug) bevaras oforandrade.
-- ---------------------------------------------------------------------
UPDATE users_permissions
SET name = 'Visa applikationer', slug = 'applications.view', module = 'applications',
    description = 'Se applikationer, mailboxar och mallar'
WHERE slug = 'systems.view';

UPDATE users_permissions
SET name = 'Hantera applikationer', slug = 'applications.manage', module = 'applications',
    description = 'Skapa/redigera/radera applikationer, mailboxar och mallar'
WHERE slug = 'systems.manage';
