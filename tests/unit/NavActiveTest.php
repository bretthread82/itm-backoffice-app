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
        $this->assertTrue(nav_active('payables', null, 'payables/12', []));
        $this->assertFalse(nav_active('payables', null, 'approvals', []));
    }

    public function testQueryMustMatch(): void
    {
        $this->assertTrue(nav_active('payables?status=draft', null, 'payables', ['status' => 'draft']));
        $this->assertFalse(nav_active('payables?status=draft', null, 'payables', ['status' => 'paid']));
        $this->assertFalse(nav_active('payables?status=draft', null, 'payables', []));
    }

    public function testUnlessDeactivatesPlainEntry(): void
    {
        $this->assertTrue(nav_active('payables', 'status', 'payables', []));
        $this->assertTrue(nav_active('payables', 'status', 'payables', ['status' => '']));
        $this->assertFalse(nav_active('payables', 'status', 'payables', ['status' => 'draft']));
    }
}
