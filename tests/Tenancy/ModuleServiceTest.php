<?php

namespace Tests\Tenancy;

use Config\Services;
use Tests\Support\Mfa\MfaTestCase;

final class ModuleServiceTest extends MfaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('modules');
        setting()->set('ModuleState.disabled', []);
    }

    public function testOptionalModulesCanBeSwitchedOffAndOn(): void
    {
        $svc = service('modules');
        $this->assertTrue($svc->enabled('invoices'));

        $svc->setEnabled('invoices', false);
        Services::resetSingle('modules');
        $this->assertFalse(service('modules')->enabled('invoices'));

        service('modules')->setEnabled('invoices', true);
        $this->assertTrue(service('modules')->enabled('invoices'));
    }

    public function testCoreModulesCannotBeSwitchedOff(): void
    {
        $this->expectException(\RuntimeException::class);
        service('modules')->setEnabled('users', false);
    }

    public function testADisabledModuleRemovesItsPermissionsForEveryoneIncludingSuperAdmins(): void
    {
        $super = $this->makeSuperUser('super@example.test');
        $this->assertTrue(service('permissions')->can($super, 'invoices.view'));

        service('modules')->setEnabled('invoices', false);

        $this->assertFalse(service('permissions')->can($super, 'invoices.view'));
        $this->assertTrue(service('permissions')->can($super, 'users.view'), 'Other modules are untouched.');
        $this->assertTrue(service('permissions')->can($super, 'modules.manage'));

        $labels = array_column(nav_tree(), 'label');
        $this->assertNotContains('Invoices', $labels);
    }

    public function testTheToggleIsAudited(): void
    {
        service('modules')->setEnabled('directory', false);

        $this->assertSame(1, db_connect()->table('audit_log')->where('action', 'module.disabled')->where('entity_id', 'directory')->countAllResults());
    }
}
