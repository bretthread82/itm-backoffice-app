<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per overdue reminder sent for a customer invoice. The unique (invoice, day) key lets the reminder command
 * claim a day by inserting first, so running it twice, or two runs overlapping, never reminds twice.
 * Foreign-key rules are given as (update, delete), the order Forge::addForeignKey takes.
 */
class CreateArReminders extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sent_on'      => ['type' => 'DATE'],
            'days_overdue' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'recipients'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['invoice_id', 'sent_on']);
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_reminders');
    }

    public function down(): void
    {
        $this->forge->dropTable('ar_reminders', true);
    }
}
