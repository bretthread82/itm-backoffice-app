<?php

declare(strict_types=1);

namespace Tests\Suppliers;

use Config\Services;
use Tests\Support\Mfa\MfaTestCase;

final class SupplierServiceTest extends MfaTestCase
{
    public function testCreateFindAndOptions(): void
    {
        $svc = service('suppliers');
        $id  = $svc->create(['code' => 'S1', 'name' => 'Acme Supplies']);
        $svc->create(['code' => 'S2', 'name' => 'Beta Fuel', 'active' => false]);

        $this->assertSame('Acme Supplies', $svc->find($id)['name']);
        $this->assertSame($id, (int) $svc->findByName('  acme SUPPLIES ')['id']);
        $this->assertSame($id, (int) $svc->findByCode('s1')['id']);
        $this->assertSame(['Acme Supplies'], array_column($svc->options(), 'name'), 'Inactive suppliers are not offered.');
        $this->assertCount(2, $svc->options(false));
        $this->assertTrue($svc->isActive($id));
    }

    public function testDuplicateCodeOrNameAndBlankValuesAreRefused(): void
    {
        $svc = service('suppliers');
        $svc->create(['code' => 'S1', 'name' => 'Acme']);

        foreach ([['code' => 'S1', 'name' => 'Other'], ['code' => 's9', 'name' => 'acme'], ['code' => '', 'name' => 'X'], ['code' => 'S3', 'name' => ' ']] as $bad) {
            try {
                $svc->create($bad);
                $this->fail('Expected a RuntimeException for ' . json_encode($bad));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUpdateKeepsOwnCodeAndIsAudited(): void
    {
        $svc = service('suppliers');
        $id  = $svc->create(['code' => 'S1', 'name' => 'Acme']);

        $svc->update($id, ['code' => 'S1', 'name' => 'Acme Ltd', 'active' => false]);

        $this->assertSame('Acme Ltd', $svc->find($id)['name']);
        $this->assertFalse($svc->isActive($id));
        $this->assertSame(1, db_connect()->table('audit_log')->where('action', 'supplier.updated')->countAllResults());
    }

    public function testSearchFindsByCodeOrName(): void
    {
        $svc = service('suppliers');
        $svc->create(['code' => 'FUEL1', 'name' => 'Island Gas']);
        $svc->create(['code' => 'X2', 'name' => 'Fuel Depot']);
        $svc->create(['code' => 'Z3', 'name' => 'Paper Co']);

        $this->assertCount(2, $svc->search('fuel'));
        $this->assertCount(3, $svc->search(''));
    }

    public function testSuppliersWorkEvenWhenTheInvoicesModuleIsOff(): void
    {
        setting()->set('ModuleState.disabled', []);
        Services::resetSingle('modules');
        service('modules')->setEnabled('invoices', false);

        $super = $this->makeSuperUser('super@example.test');
        $this->assertTrue(service('permissions')->can($super, 'suppliers.manage'));
        $this->assertGreaterThan(0, service('suppliers')->create(['code' => 'S1', 'name' => 'Acme']));
    }
}
