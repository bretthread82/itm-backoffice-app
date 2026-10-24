<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks the one contact per customer who receives notifications sent to that customer. Kept apart from
 * `is_primary` because the person we call and the person who gets the emails are often different.
 */
class AddNotifyToCustomerContacts extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('customer_contacts', [
            'is_notify' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'is_primary'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('customer_contacts', 'is_notify');
    }
}
