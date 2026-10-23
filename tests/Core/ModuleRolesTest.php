<?php

namespace Tests\Core;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Backoffice;

final class ModuleRolesTest extends CIUnitTestCase
{
    private Backoffice $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = config(Backoffice::class);
    }

    public function testEveryModuleWithPermissionsDeclaresAManagerRole(): void
    {
        foreach ($this->config->modules as $key => $module) {
            if ($module['permissions'] === []) {
                continue;
            }

            $this->assertArrayHasKey('manager', $module['roles'] ?? [], "Module {$key} needs a manager role.");
        }
    }

    public function testRolePermissionsExistAndKeysAreUnique(): void
    {
        $known = $this->config->allPermissions();
        $roles = $this->config->roleDefinitions();
        $count = 0;

        foreach ($this->config->modules as $module) {
            $count += count($module['roles'] ?? []);
        }

        $this->assertCount(count($this->config->systemRoles) + $count, $roles, 'Role keys must be unique.');

        foreach ($roles as $key => $role) {
            foreach ($role['permissions'] as $permission) {
                $this->assertTrue($permission === '*' || isset($known[$permission]), "Role {$key} lists unknown permission {$permission}.");
            }
        }
    }

    public function testAManagerHoldsEverythingTheUserRoleHolds(): void
    {
        $roles = $this->config->roleDefinitions();

        $this->assertEqualsCanonicalizing(['invoices.view', 'invoices.reports', 'suppliers.view'], $roles['invoice_user']['permissions']);
        $this->assertEmpty(array_diff($roles['invoice_user']['permissions'], $roles['invoice_workflow']['permissions']));
        $this->assertContains('invoices.workflow', $roles['invoice_workflow']['permissions']);
        $this->assertNotContains('invoices.workflow', $roles['invoice_user']['permissions']);

        $this->assertSame(['directory.view'], $roles['directory_user']['permissions']);
        $this->assertContains('directory.view', $roles['directory_manager']['permissions']);
        $this->assertContains('departments.manage', $roles['directory_manager']['permissions']);
    }

    public function testGroupLevelAccessIsNeverInARole(): void
    {
        foreach ($this->config->roleDefinitions() as $key => $role) {
            if ($key !== 'super_admin') {
                $this->assertNotContains('tenancy.group_level', $role['permissions'], $key);
            }
        }
    }
}
