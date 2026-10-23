<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class NavActiveTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('app');
    }

    public function testPathOnlyMatchesSubpaths(): void
    {
        $this->assertTrue(nav_active('invoices', null, 'invoices/12', []));
        $this->assertFalse(nav_active('invoices', null, 'approvals', []));
    }

    public function testQueryMustMatch(): void
    {
        $this->assertTrue(nav_active('invoices?status=draft', null, 'invoices', ['status' => 'draft']));
        $this->assertFalse(nav_active('invoices?status=draft', null, 'invoices', ['status' => 'paid']));
        $this->assertFalse(nav_active('invoices?status=draft', null, 'invoices', []));
    }

    public function testUnlessDeactivatesPlainEntry(): void
    {
        $this->assertTrue(nav_active('invoices', 'status', 'invoices', []));
        $this->assertTrue(nav_active('invoices', 'status', 'invoices', ['status' => '']));
        $this->assertFalse(nav_active('invoices', 'status', 'invoices', ['status' => 'draft']));
    }
}
