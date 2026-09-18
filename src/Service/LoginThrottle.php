<?php

declare(strict_types=1);

namespace Mpgw\Service;

use PDO;

/**
 * Brute-force-skydd for /admin/login: rakar misslyckade forsok per
 * (ip, email) och lasar ute under ett vaxande tidsfonster efter for manga
 * fel i rad. Rakningen nollstalls vid lyckad inloggning eller nar
 * fonstret sedan senaste felet har passerat (ingen separat stadningsjobb
 * behovs).
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900; // 15 min - bade for att rakningen ska nollstallas och for lockout-langden

    public function __construct(private readonly PDO $db)
    {
    }

    public function isLocked(string $ip, string $email): bool
    {
        $row = $this->find($ip, $email);
        if ($row === null) {
            return false;
        }

        if ($this->windowExpired($row)) {
            return false;
        }

        return (int) $row['failed_count'] >= self::MAX_ATTEMPTS;
    }

    public function recordFailure(string $ip, string $email): void
    {
        $row = $this->find($ip, $email);
        $now = date('Y-m-d H:i:s');

        if ($row === null || $this->windowExpired($row)) {
            $this->db->prepare(
                'INSERT INTO login_attempts (ip_address, email, failed_count, first_failed_at, last_failed_at)
                 VALUES (?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE failed_count = 1, first_failed_at = VALUES(first_failed_at), last_failed_at = VALUES(last_failed_at)'
            )->execute([$ip, $email, $now, $now]);
            return;
        }

        $this->db->prepare(
            'UPDATE login_attempts SET failed_count = failed_count + 1, last_failed_at = ? WHERE ip_address = ? AND email = ?'
        )->execute([$now, $ip, $email]);
    }

    public function clear(string $ip, string $email): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? AND email = ?')->execute([$ip, $email]);
    }

    /** @return array{failed_count:int,first_failed_at:string,last_failed_at:string}|null */
    private function find(string $ip, string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT failed_count, first_failed_at, last_failed_at FROM login_attempts WHERE ip_address = ? AND email = ?');
        $stmt->execute([$ip, $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array{failed_count:int,first_failed_at:string,last_failed_at:string} $row */
    private function windowExpired(array $row): bool
    {
        return (time() - strtotime($row['last_failed_at'])) > self::WINDOW_SECONDS;
    }
}
