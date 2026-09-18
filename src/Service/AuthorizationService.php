<?php

declare(strict_types=1);

namespace Mpgw\Service;

use Mpgw\Infrastructure\Db\Repository\RoleRepository;
use Mpgw\Infrastructure\Db\Repository\UserRepository;

/**
 * RBAC-rattighetskontroll. Rattighetsslugs beraknas en gang vid inloggning
 * och cachas i sessionen (samma monster som BRF Flow:s
 * $_SESSION['authz_payload']) istallet for att slas upp mot databasen pa
 * varje request - enklast for ett internt admin-verktyg av den har
 * storleken.
 *
 * Kand begransning: om en annan administrator andrar en inloggad
 * anvandares roll/rattigheter mitt i en session, ser den inloggade
 * anvandaren inte forandringen forran nasta inloggning (eller tills
 * refreshCurrentSession() kallas explicit for den EGNA sessionen, se
 * UsersController/RolesController).
 */
final class AuthorizationService
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
    ) {
    }

    public function primeSessionForUser(int $userId): void
    {
        $roles = $this->users->rolesForUser($userId);

        $slugs = [];
        foreach ($roles as $role) {
            foreach ($this->roles->permissionSlugsForRole((int) $role['id']) as $slug) {
                $slugs[$slug] = true;
            }
        }

        $_SESSION['authz_permissions'] = array_keys($slugs);
        $_SESSION['authz_role_names'] = array_column($roles, 'name');
        $_SESSION['authz_primed_at'] = time();
    }

    public function can(string $permissionSlug): bool
    {
        return in_array($permissionSlug, $_SESSION['authz_permissions'] ?? [], true);
    }

    /**
     * Raknar om rattigheter/rollnamn for den AKTUELLT inloggade anvandaren
     * i den har sessionen. Anvands efter att en admin andrat sin egen
     * roll/rattigheter, sa den nya behorigheten galler direkt utan
     * ny inloggning.
     */
    public function refreshCurrentSession(): void
    {
        if (!empty($_SESSION['admin_user_id'])) {
            $this->primeSessionForUser((int) $_SESSION['admin_user_id']);
        }
    }
}
