<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Mappar authentik-grupper (groups-claim) till atkomst och roll-slug.
 */
final class OidcRoleMapper
{
    public function __construct(
        private readonly string $accessGroup,
        private readonly string $adminGroup,
        private readonly string $defaultRole,
        private readonly string $adminRole,
    ) {
    }

    /** @param array<int,mixed> $groups */
    public function hasAccess(array $groups): bool
    {
        return in_array($this->accessGroup, $groups, true) || in_array($this->adminGroup, $groups, true);
    }

    /** Roll-slug for anvandaren, eller null om gruppmedlemskap saknas. @param array<int,mixed> $groups */
    public function roleSlug(array $groups): ?string
    {
        if (in_array($this->adminGroup, $groups, true)) {
            return $this->adminRole;
        }

        return in_array($this->accessGroup, $groups, true) ? $this->defaultRole : null;
    }
}
