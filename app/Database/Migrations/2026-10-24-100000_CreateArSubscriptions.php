<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Customer subscriptions: a recurring schedule that raises a draft customer invoice each cycle.
 * ar_subscription_invoices has one row per cycle (unique per subscription and bill date), so a cycle is never billed twice;
 * ar_subscription_notices has one row per "coming up" notice, so the reminder command can be run any number of times.
 * Foreign-key rules are given as (update, delete), the order Forge::addForeignKey takes.
 */
class CreateArSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'company_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'invoice_type_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'customer_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'customer'           => ['type' => 'VARCHAR', 'constraint' => 200],
            'description'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'amount'             => ['type' => 'DECIMAL', 'constraint' => '14,2'],
            'currency'           => ['type' => 'VARCHAR', 'constraint' => 3],
            'frequency'          => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'monthly'],
            'start_date'         => ['type' => 'DATE'],
            'next_bill_date'     => ['type' => 'DATE'],
            'bill_day'           => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true],
            'end_date'           => ['type' => 'DATE', 'null' => true],
            'payment_terms_days' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 30],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'active'],
            'owner_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'notes'              => ['type' => 'TEXT', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('company_id');
        $this->forge->addKey('customer_id');
        $this->forge->addKey(['status', 'next_bill_date']);
        $this->forge->addKey('owner_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('invoice_type_id', 'ar_invoice_types', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('ar_subscriptions');

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'subscription_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'bill_date'       => ['type' => 'DATE'],
            'invoice_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['subscription_id', 'bill_date']);
        $this->forge->addKey('invoice_id');
        $this->forge->addForeignKey('subscription_id', 'ar_subscriptions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('ar_subscription_invoices');

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'subscription_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'bill_date'       => ['type' => 'DATE'],
            'lead_days'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'recipients'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['subscription_id', 'bill_date', 'lead_days']);
        $this->forge->addForeignKey('subscription_id', 'ar_subscriptions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_subscription_notices');
    }

    public function down(): void
    {
        $this->forge->dropTable('ar_subscription_notices', true);
        $this->forge->dropTable('ar_subscription_invoices', true);
        $this->forge->dropTable('ar_subscriptions', true);
    }
}
