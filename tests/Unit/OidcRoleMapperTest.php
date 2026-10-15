<?php

declare(strict_types=1);

namespace Mpgw\Tests\Unit;

use Mpgw\Service\OidcRoleMapper;
use PHPUnit\Framework\TestCase;

final class OidcRoleMapperTest extends TestCase
{
    private function mapper(): OidcRoleMapper
    {
        return new OidcRoleMapper('GRP-MPGW-Users', 'GRP-MPGW-Admin', 'reader', 'administrator');
    }

    public function testUsersGroupGivesReader(): void
    {
        $this->assertTrue($this->mapper()->hasAccess(['GRP-MPGW-Users']));
        $this->assertSame('reader', $this->mapper()->roleSlug(['x', 'GRP-MPGW-Users']));
    }

    public function testAdminGroupGivesAdministratorEvenWithoutUsersGroup(): void
    {
        $this->assertTrue($this->mapper()->hasAccess(['GRP-MPGW-Admin']));
        $this->assertSame('administrator', $this->mapper()->roleSlug(['GRP-MPGW-Admin']));
        $this->assertSame('administrator', $this->mapper()->roleSlug(['GRP-MPGW-Users', 'GRP-MPGW-Admin']));
    }

    public function testNoGroupMeansNoAccess(): void
    {
        $this->assertFalse($this->mapper()->hasAccess(['other']));
        $this->assertNull($this->mapper()->roleSlug([]));
    }
}
