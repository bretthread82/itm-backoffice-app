<?php

declare(strict_types=1);

namespace Tests\Payables;

use App\Database\Migrations\RenameInvoicesModuleToPayables;
use Tests\Support\Payables\PayablesTestCase;

/**
 * The Invoices -> Payables rename must carry existing grants, role assignments, the module switch and
 * notification preferences across, and be reversible.
 */
final class RenameToPayablesMigrationTest extends PayablesTestCase
{
    public function testUpMovesStoredKeysAndDownRestoresThem(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-10-23-140000_RenameInvoicesModuleToPayables.php';

        $migration = new RenameInvoicesModuleToPayables();
        $userId    = $this->makeUserNamed('Una User', 'una@example.test');
        $roleId    = $this->roleId('payables_user');
        $permId    = (int) $this->db->table('permissions')->where('key', 'payables.view')->get()->getRow()->id;

        $this->db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        $this->db->table('notification_preferences')->insert(['user_id' => $userId, 'type' => 'payables.paid', 'in_app' => 0, 'email' => 0]);
        setting()->set('ModuleState.disabled', ['payables', 'demo']);

        // Back to the old names first, as a database from before the rename would look.
        $migration->down();

        $this->assertSame('invoices.view', $this->db->table('permissions')->where('id', $permId)->get()->getRow()->key);
        $this->assertSame('invoice_user', $this->db->table('roles')->where('id', $roleId)->get()->getRow()->key);
        $this->assertSame(1, $this->db->table('notification_preferences')->where(['user_id' => $userId, 'type' => 'invoice.paid'])->countAllResults());

        $migration->up();

        $this->assertSame('payables.view', $this->db->table('permissions')->where('id', $permId)->get()->getRow()->key);
        $this->assertSame('payables', $this->db->table('permissions')->where('id', $permId)->get()->getRow()->module);
        $this->assertSame('payables_user', $this->db->table('roles')->where('id', $roleId)->get()->getRow()->key);
        $this->assertSame(1, $this->db->table('user_roles')->where(['user_id' => $userId, 'role_id' => $roleId])->countAllResults());
        $pref = $this->db->table('notification_preferences')->where(['user_id' => $userId, 'type' => 'payables.paid'])->get()->getRow();
        $this->assertSame(0, (int) $pref->in_app);
        $this->assertSame(['payables', 'demo'], unserialize((string) $this->db->table('settings')->where('key', 'disabled')->get()->getRow()->value));
    }
}
