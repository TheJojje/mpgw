-- ---------------------------------------------------------------------
-- deleted_emails: "tombstones" for mail som en administrator raderat.
-- Sjalva mailet (raw_emails + extraherade records) tas bort, men
-- content_hash/message_id sparas har sa att MailboxPoller inte
-- importerar om det vid nasta synkronisering (t.ex. om last_seen_uid
-- nollstalls eller mailboxen byts). Innehaller inget mailinnehall.
-- Ingen FK pa mailbox_id: tombstonen ska overleva att mailboxen tas bort.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deleted_emails (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id          INT UNSIGNED NULL,
    content_hash        CHAR(64) NOT NULL,
    message_id          VARCHAR(500) NULL,
    imap_uid            VARCHAR(64) NULL,
    deleted_by          INT UNSIGNED NULL,
    deleted_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deleted_emails_content_hash (content_hash),
    KEY idx_deleted_emails_mailbox_message (mailbox_id, message_id(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO users_permissions (name, slug, module, description) VALUES
    ('Hantera mailhistorik', 'emails.manage', 'emails', 'Radera inkomna mail');

-- Administrator: alla rattigheter (inklusive den nya).
INSERT IGNORE INTO users_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM users_roles r JOIN users_permissions p ON 1 = 1
WHERE r.slug = 'administrator';
