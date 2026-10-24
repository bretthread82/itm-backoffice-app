<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The Invoices module became Payables. Moves the stored module key (permissions, roles, module switch,
 * company capabilities, notification types and links) so existing grants, assignments and preferences
 * keep working. Audit history and uploaded files keep their old names.
 */
class RenameInvoicesModuleToPayables extends Migration
{
    private const NOTIFICATION_EVENTS = [
        'submitted', 'received', 'step_approved', 'processed', 'paid', 'rejected', 'returned', 'resubmitted',
        'returned_step', 'override', 'notify_step', 'claimed', 'released', 'document_added',
    ];

    public function up(): void
    {
        $this->rename('invoices', 'payables', ['invoice_user' => 'payables_user', 'invoice_workflow' => 'payables_workflow'], 'invoice', 'payables');
    }

    public function down(): void
    {
        $this->rename('payables', 'invoices', ['payables_user' => 'invoice_user', 'payables_workflow' => 'invoice_workflow'], 'payables', 'invoice');
    }

    /**
     * @param array<string, string> $roles old role key => new role key
     */
    private function rename(string $from, string $to, array $roles, string $fromEvent, string $toEvent): void
    {
        // Permissions: keep the row (and every grant on it) unless the new key already exists.
        foreach ($this->db->table('permissions')->like('key', $from . '.', 'after')->get()->getResultArray() as $old) {
            $newKey   = $to . substr((string) $old['key'], strlen($from));
            $existing = $this->db->table('permissions')->where('key', $newKey)->get()->getRowArray();

            if ($existing) {
                $this->db->query('UPDATE IGNORE role_permissions SET permission_id = ? WHERE permission_id = ?', [$existing['id'], $old['id']]);
                $this->db->table('permissions')->where('id', $old['id'])->delete();
            } else {
                $this->db->table('permissions')->where('id', $old['id'])->update(['key' => $newKey, 'module' => $to]);
            }
        }

        // Roles: keep the row so user assignments survive; the seeder refreshes the name and description.
        foreach ($roles as $oldKey => $newKey) {
            $old = $this->db->table('roles')->where('key', $oldKey)->get()->getRowArray();

            if (! $old) {
                continue;
            }

            $existing = $this->db->table('roles')->where('key', $newKey)->get()->getRowArray();

            if ($existing) {
                foreach (['user_roles', 'role_permissions', 'entra_group_role_map'] as $table) {
                    $this->db->query("UPDATE IGNORE {$table} SET role_id = ? WHERE role_id = ?", [$existing['id'], $old['id']]);
                }
                $this->db->table('roles')->where('id', $old['id'])->delete();
            } else {
                $this->db->table('roles')->where('id', $old['id'])->update(['key' => $newKey]);
            }
        }

        // Module switch (Admin > Modules): a serialized list of switched-off module keys.
        $row = $this->db->table('settings')->where('class', 'Config\ModuleState')->where('key', 'disabled')->get()->getRowArray();

        if ($row) {
            $list = @unserialize((string) $row['value'], ['allowed_classes' => false]);

            if (is_array($list) && in_array($from, $list, true)) {
                $list = array_values(array_unique(array_map(static fn ($key) => $key === $from ? $to : $key, $list)));
                $this->db->table('settings')->where('id', $row['id'])->update(['value' => serialize($list)]);
            }
        }

        // Per-company capabilities (stored as '<module>.<key>').
        $this->db->query('UPDATE IGNORE user_company_capabilities SET capability = CONCAT(?, SUBSTRING(capability, ?)) WHERE capability LIKE ?', [
            $to . '.', strlen($from) + 2, $from . '.%',
        ]);

        // Notification types, preferences and the links stored on existing notifications.
        foreach (self::NOTIFICATION_EVENTS as $event) {
            foreach (['notifications', 'notification_preferences'] as $table) {
                $this->db->query("UPDATE IGNORE {$table} SET type = ? WHERE type = ?", [$toEvent . '.' . $event, $fromEvent . '.' . $event]);
            }
        }

        $this->db->query('UPDATE notifications SET url = CONCAT(?, SUBSTRING(url, ?)) WHERE url LIKE ?', [
            $to . '/', strlen($from) + 2, $from . '/%',
        ]);
    }
}
