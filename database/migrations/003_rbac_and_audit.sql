-- RBAC (roller/rattigheter) och audit-loggning (log_events), samt namn och
-- senaste inloggning pa users. Se AuthorizationService/AuditLogger.
-- Idempotent (migrate.php kor om SAMTLIGA filer varje gang - se bin/migrate.php).
SET NAMES utf8mb4;

-- users: lagg till 'name' (saknas idag - AuthController/ViewRenderer behover
-- ett visningsnamn for sidebar-widgeten) och 'last_login_at' (snabb
-- visning; fullstandig historik finns i log_events).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'name'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN name VARCHAR(190) NOT NULL DEFAULT '''' AFTER email',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Befintliga rader far e-postens lokaldel som provisoriskt namn sa
-- UI:t inte visar tomt - riktiga namn satts sedan via Anvandare-vyn.
UPDATE users SET name = SUBSTRING_INDEX(email, '@', 1) WHERE name = '';

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- users_roles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users_roles (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    slug         VARCHAR(120) NOT NULL,
    description  VARCHAR(255) NULL,
    is_system    TINYINT(1) NOT NULL DEFAULT 0,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- users_permissions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users_permissions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(190) NOT NULL,
    slug         VARCHAR(120) NOT NULL,
    module       VARCHAR(64) NOT NULL,
    description  VARCHAR(255) NULL,
    is_system    TINYINT(1) NOT NULL DEFAULT 1,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_permissions_slug (slug),
    KEY idx_users_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- users_role_permissions (koppling roll <-> rattighet)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users_role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_urp_role FOREIGN KEY (role_id) REFERENCES users_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_urp_permission FOREIGN KEY (permission_id) REFERENCES users_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- users_user_roles (koppling anvandare <-> roll) - stodjer flera roller
-- per anvandare aven om UI:t idag bara latt hanterar en at gangen.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users_user_roles (
    user_id     INT UNSIGNED NOT NULL,
    role_id     INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_uur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uur_role FOREIGN KEY (role_id) REFERENCES users_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- log_events: audit-logg. Sparar bara nytt tillstand som JSON
-- (content_params) - fore/efter-diff beraknas i UI:t (AuditDiffer) genom
-- att jamfora tva pa varandra foljande rader for samma (object_type,
-- object_id), inte via separata old/new-kolumner.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module          VARCHAR(64) NOT NULL,
    action          VARCHAR(64) NOT NULL,
    level           ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
    source          ENUM('ui','api','system','cron') NOT NULL DEFAULT 'ui',
    actor_user_id   INT UNSIGNED NULL,
    object_type     VARCHAR(64) NULL,
    object_id       INT UNSIGNED NULL,
    content         VARCHAR(255) NULL,
    content_params  JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_log_events_module_time (module, created_at),
    KEY idx_log_events_object (object_type, object_id, created_at),
    KEY idx_log_events_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Seed: rattigheter (en per modul+atgard). INSERT IGNORE pa unik slug
-- gor blocket sakert att kora om.
--
-- OBS: systems.view/systems.manage doper 004_rename_systems_to_applications.sql
-- senare om till applications.view/applications.manage (samma UPDATE-monster
-- som tabellen systems->applications). Om det redan kort far INSERT IGNORE
-- har INTE lagga tillbaka de gamla systems.*-sluggarna - migrate.php kor om
-- samtliga filer varje gang, och en statisk INSERT IGNORE kanner inte till
-- att 004 redan bytt namn pa dem, vilket annars skapar en ny systems.view-
-- rad som 004 sedan kolliderar med den redan omdopta applications.view.
SET @applications_permission_exists = (
    SELECT COUNT(*) FROM users_permissions WHERE slug = 'applications.view'
);

SET @sql = IF(
    @applications_permission_exists = 0,
    "INSERT IGNORE INTO users_permissions (name, slug, module, description) VALUES
        ('Visa system', 'systems.view', 'systems', 'Se system, mailboxar och mallar'),
        ('Hantera system', 'systems.manage', 'systems', 'Skapa/redigera/radera system, mailboxar och mallar')",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO users_permissions (name, slug, module, description) VALUES
    ('Visa mailhistorik',   'emails.view',      'emails',   'Se inkommande mail och extraherade falt'),
    ('Visa larm',           'alerts.view',      'alerts',   'Se larmlistan'),
    ('Hantera larm',        'alerts.manage',    'alerts',   'Kvittera/losa larm'),
    ('Visa API-nycklar',    'api_keys.view',    'api_keys', 'Se API-nycklar'),
    ('Hantera API-nycklar', 'api_keys.manage',  'api_keys', 'Skapa/inaktivera API-nycklar'),
    ('Visa anvandare',      'users.view',       'users',    'Se anvandarlistan'),
    ('Hantera anvandare',   'users.manage',     'users',    'Skapa/redigera/inaktivera anvandare'),
    ('Visa roller',         'roles.view',       'roles',    'Se roller och rattigheter'),
    ('Hantera roller',      'roles.manage',     'roles',    'Skapa/redigera/radera roller och tilldela rattigheter'),
    ('Visa logg',           'logs.view',        'logs',     'Se granskningsloggen (audit log)');

-- Seed: roller. is_system=1 skyddar mot radering i RolesController::delete.
INSERT IGNORE INTO users_roles (name, slug, description, is_system) VALUES
    ('Administratör', 'administrator', 'Full åtkomst till alla funktioner', 1),
    ('Läsare',         'reader',        'Läsbehörighet till allt, ingen möjlighet att ändra', 1);

-- Administrator: alla rattigheter.
INSERT IGNORE INTO users_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM users_roles r JOIN users_permissions p ON 1 = 1
WHERE r.slug = 'administrator';

-- Reader: endast .view-rattigheter.
INSERT IGNORE INTO users_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM users_roles r JOIN users_permissions p ON p.slug LIKE '%.view'
WHERE r.slug = 'reader';

-- Befintliga anvandare: tilldela Administrator-rollen sa ingen las ute nar
-- RBAC:et aktiveras (manuell finjustering gors sedan via UI:t).
INSERT IGNORE INTO users_user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN users_roles r ON r.slug = 'administrator';
