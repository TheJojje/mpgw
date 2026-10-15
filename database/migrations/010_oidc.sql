-- ---------------------------------------------------------------------
-- OIDC/SSO (authentik): users.oidc_sub = stabil identifierare (sub-claim)
-- fran identitetsleverantoren. SSO-konton far en slumpad, oanvandbar
-- password_hash, sa password_hash behover inte bli nullable.
-- Idempotent (kors om vid varje bin/migrate.php).
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'oidc_sub'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN oidc_sub VARCHAR(255) NULL AFTER email, ADD UNIQUE KEY uq_users_oidc_sub (oidc_sub)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
