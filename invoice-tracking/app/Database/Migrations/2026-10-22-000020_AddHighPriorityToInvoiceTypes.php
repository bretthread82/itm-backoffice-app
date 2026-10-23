<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * High priority invoice types. The flag is set on the default type, copied to each company's type, and
 * captured on the invoice when it is submitted (so editing a type never changes invoices in flight).
 * user_notifications.priority lets the bell mark the alerts these invoices raise.
 */
class AddHighPriorityToInvoiceTypes extends Migration
{
    private const TABLES = ['default_invoice_types', 'invoice_types', 'invoices'];

    public function up(): void
    {
        $flag = ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0];

        foreach (self::TABLES as $table) {
            if (! $this->db->fieldExists('high_priority', $table)) {
                $this->forge->addColumn($table, ['high_priority' => $flag]);
            }
        }

        if (! $this->db->fieldExists('priority', 'user_notifications')) {
            $this->forge->addColumn('user_notifications', ['priority' => $flag]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->forge->dropColumn($table, 'high_priority');
        }
        $this->forge->dropColumn('user_notifications', 'priority');
    }
}
