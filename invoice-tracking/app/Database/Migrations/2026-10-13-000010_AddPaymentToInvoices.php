<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPaymentToInvoices extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('invoices', [
            'payment_reference' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'po_reference'],
            'payment_date'      => ['type' => 'DATE', 'null' => true, 'after' => 'payment_reference'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('invoices', ['payment_reference', 'payment_date']);
    }
}
