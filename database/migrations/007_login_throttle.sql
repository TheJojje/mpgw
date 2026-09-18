-- ---------------------------------------------------------------------
-- login_attempts: forsok-rakare for brute-force-skydd pa /admin/login.
-- En rad per (ip, email)-par under det aktuella spar-fonstret; lockout
-- rakas ut i applikationskod (LoginThrottle) fran failed_count/first_failed_at
-- istallet for att lagra ett separat "locked_until"-falt, sa klockan
-- aterstalls naturligt nar fonstret passerar aven om ingen stadning kors.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address          VARCHAR(45) NOT NULL,
    email               VARCHAR(320) NOT NULL,
    failed_count        INT UNSIGNED NOT NULL DEFAULT 1,
    first_failed_at     DATETIME NOT NULL,
    last_failed_at      DATETIME NOT NULL,
    UNIQUE KEY uq_login_attempts_ip_email (ip_address, email),
    KEY idx_login_attempts_last_failed (last_failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
