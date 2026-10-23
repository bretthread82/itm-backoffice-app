<?php

declare(strict_types=1);

namespace Tests\Support\Database\Seeds;

use App\Models\UserModel;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;

/**
 * Throwaway data for tests/e2e/smoke.sh. Never run against a real database.
 *
 *   php spark db:seed "Tests\Support\Database\Seeds\E2eSeeder"
 *
 * Creates sue@e2e.test (can submit), alan@e2e.test (approver) and admin@e2e.test (administrator), all with password
 * "E2e-Test-Password-1", one company, one invoice type and a two-step route.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $users = model(UserModel::class);
        $mk    = function (string $email, string $name, int $admin = 0) use ($users): int {
            $users->save(new User(['email' => $email, 'password' => 'E2e-Test-Password-1', 'display_name' => $name, 'active' => 1, 'is_admin' => $admin]));
            $id = (int) $users->getInsertID();
            $users->addToDefaultGroup($users->findById($id));

            return $id;
        };

        $sue  = $mk('sue@e2e.test', 'Sue Submitter');
        $alan = $mk('alan@e2e.test', 'Alan Accountant');
        $mk('admin@e2e.test', 'Ada Admin', 1);

        $this->db->table('companies')->insert(['code' => 'ACME', 'name' => 'Acme Ltd', 'active' => 1]);
        $co = (int) $this->db->insertID();
        $this->db->table('user_companies')->insert(['user_id' => $sue, 'company_id' => $co, 'can_submit' => 1, 'can_approve' => 0]);
        $this->db->table('user_companies')->insert(['user_id' => $alan, 'company_id' => $co, 'can_submit' => 0, 'can_approve' => 1]);
        $this->db->table('suppliers')->insertBatch([['code' => 'S1', 'name' => 'Power Co', 'active' => 1], ['code' => 'S2', 'name' => 'Bad', 'active' => 1]]);
        $this->db->table('invoice_types')->insert(['company_id' => $co, 'name' => 'Utilities', 'active' => 1]);
        $type = (int) $this->db->insertID();
        $this->db->table('routes')->insert(['company_id' => $co, 'invoice_type_id' => $type, 'variant' => 'default', 'variant_trigger' => 'none', 'version' => 1, 'active' => 1]);
        $route = (int) $this->db->insertID();
        $this->db->table('job_titles')->insert(['name' => 'Clerk']);
        $clerk = (int) $this->db->insertID();
        $this->db->table('job_titles')->insert(['name' => 'Accountant']);
        $acct = (int) $this->db->insertID();
        $this->db->table('route_steps')->insertBatch([
            ['route_id' => $route, 'step_order' => 1, 'step_type' => 'submit', 'job_title_id' => $clerk, 'assignee_kind' => 'user', 'assignee_user_id' => $sue, 'assignee_label' => 'Sue Submitter'],
            ['route_id' => $route, 'step_order' => 2, 'step_type' => 'approve', 'job_title_id' => $acct, 'assignee_kind' => 'user', 'assignee_user_id' => $alan, 'assignee_label' => 'Alan Accountant'],
        ]);
    }
}
