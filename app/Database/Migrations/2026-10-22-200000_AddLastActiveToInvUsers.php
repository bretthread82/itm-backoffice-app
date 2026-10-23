<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The invoices module warns when a route names someone who has never signed in, so its read-only
 * user view needs Shield's last_active.
 */
class AddLastActiveToInvUsers extends Migration
{
    public function up(): void
    {
        $this->db->query($this->view(true));
    }

    public function down(): void
    {
        $this->db->query($this->view(false));
    }

    private function view(bool $withLastActive): string
    {
        return "CREATE OR REPLACE VIEW inv_users AS "
            . "SELECT u.id, u.username, "
            . "COALESCE(NULLIF(sp.display_name, ''), NULLIF(TRIM(CONCAT_WS(' ', sp.first_name, sp.last_name)), ''), u.username) AS display_name, "
            . "CASE WHEN u.deleted_at IS NOT NULL THEN 0 WHEN sp.status IS NULL THEN 1 WHEN sp.status = 'active' THEN 1 ELSE 0 END AS active, "
            . "ai.secret AS email"
            . ($withLastActive ? ", u.last_active AS last_active " : " ")
            . "FROM users u "
            . "LEFT JOIN staff_profiles sp ON sp.user_id = u.id AND sp.deleted_at IS NULL "
            . "LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.type = 'email_password'";
    }
}
