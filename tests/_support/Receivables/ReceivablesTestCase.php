<?php

declare(strict_types=1);

namespace Tests\Support\Receivables;

use App\Core\Receivables\ArFiles;
use Tests\Support\Payables\PayablesTestCase;

/**
 * PayablesTestCase plus builders for the ar_ tables: per-company receivables rights, routes, teams and customer invoices.
 * Users, companies, group-level grants and temp files come from the invoices base class.
 */
abstract class ReceivablesTestCase extends PayablesTestCase
{
    protected function tearDown(): void
    {
        try {
            foreach ([...$this->db->table('ar_files')->get()->getResultArray(), ...$this->db->table('ar_documents')->get()->getResultArray()] as $row) {
                @unlink(WRITEPATH . 'uploads/' . ArFiles::key($row));
            }
        } catch (\Throwable) {
            // The schema may already be gone.
        }

        parent::tearDown();
    }

    /**
     * Makes the user a member of the company with the given receivables rights ('submit', 'approve', 'collect').
     * The invoices rights are deliberately left off, so a test proves receivables stands on its own.
     *
     * @param list<string> $rights
     */
    protected function grant(int $userId, int $companyId, array $rights): void
    {
        if ($this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId])->countAllResults() === 0) {
            $this->join($userId, $companyId, false, false);
        }

        foreach ($rights as $right) {
            $this->db->table('user_company_capabilities')->insert(['user_id' => $userId, 'company_id' => $companyId, 'capability' => 'receivables.' . $right]);
        }
    }

    /** The two variants the migration seeds; the test database is emptied between tests, so they are put back on demand. */
    protected function seedVariants(): void
    {
        if ($this->db->table('ar_route_variants')->where('variant_key', 'default')->countAllResults() === 0) {
            $this->db->table('ar_route_variants')->insertBatch([
                ['variant_key' => 'default', 'name' => 'Standard', 'is_standard' => 1, 'active' => 1, 'sort_order' => 0],
                ['variant_key' => 'extra_approval', 'name' => 'High Value', 'is_standard' => 0, 'active' => 1, 'sort_order' => 10],
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $steps type (submit|approve|issue|notify), user, team, role, label, email, sla
     *
     * @return array{type: int, route: int}
     */
    protected function makeArRoute(int $companyId, array $steps, string $typeName = 'Sales', string $variant = 'default', bool $active = true, string $trigger = 'none', ?string $triggerValue = null): array
    {
        $type = $this->db->table('ar_invoice_types')->where(['company_id' => $companyId, 'name' => $typeName])->get()->getRowArray();

        if ($type === null) {
            $this->db->table('ar_invoice_types')->insert(['company_id' => $companyId, 'name' => $typeName, 'active' => 1]);
            $typeId = (int) $this->db->insertID();
        } else {
            $typeId = (int) $type['id'];
        }

        $this->seedVariants();

        $this->db->table('ar_routes')->insert([
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'variant' => $variant, 'variant_trigger' => $trigger,
            'trigger_value' => $triggerValue, 'version' => 1, 'active' => $active ? 1 : 0,
        ]);
        $routeId = (int) $this->db->insertID();

        foreach ($steps as $i => $s) {
            $titleId = null;

            if (! empty($s['role'])) {
                $row = $this->db->table('ar_job_titles')->where('name', $s['role'])->get()->getRowArray();

                if ($row === null) {
                    $this->db->table('ar_job_titles')->insert(['name' => $s['role']]);
                    $titleId = (int) $this->db->insertID();
                } else {
                    $titleId = (int) $row['id'];
                }
            }

            $this->db->table('ar_route_steps')->insert([
                'route_id' => $routeId, 'step_order' => $i + 1, 'step_type' => $s['type'] ?? 'approve', 'job_title_id' => $titleId,
                'assignee_kind' => $s['kind'] ?? (isset($s['team']) ? 'team' : 'user'), 'assignee_user_id' => $s['user'] ?? null, 'team_id' => $s['team'] ?? null,
                'assignee_label' => $s['label'] ?? null, 'assignee_email' => $s['email'] ?? null, 'sla_days' => $s['sla'] ?? null,
            ]);
        }

        return ['type' => $typeId, 'route' => $routeId];
    }

    /**
     * @param list<int> $members
     */
    protected function makeArTeam(string $name, array $members = [], ?string $groupEmail = null): int
    {
        $this->db->table('ar_teams')->insert(['name' => $name, 'group_email' => $groupEmail, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $this->db->insertID();

        foreach ($members as $userId) {
            $this->db->table('ar_team_members')->insert(['team_id' => $id, 'user_id' => $userId]);
        }

        return $id;
    }

    /**
     * An invoice already issued to the customer, written straight to the table (the route is tested elsewhere).
     *
     * @param array<string, mixed> $over
     */
    protected function makeIssued(int $companyId, int $raiser, array $over = []): int
    {
        static $n = 0;
        $n++;

        $this->db->table('ar_invoice_types')->insert(['company_id' => $companyId, 'name' => 'Issued ' . $n . random_int(1, 999999), 'active' => 1]);
        $typeId = (int) $this->db->insertID();

        $this->db->table('ar_invoices')->insert($over + [
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'submitted_by' => $raiser, 'customer' => 'Harbour Foods',
            'invoice_number' => 'ISS-' . $n, 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'amount' => '250.00', 'currency' => 'USD',
            'status' => 'issued', 'sent_to' => 'ap@harbourfoods.example', 'issued_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed> */
    protected function arRow(int $id): array
    {
        return $this->db->table('ar_invoices')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<array<string, mixed>> */
    protected function arActions(int $invoiceId): array
    {
        return $this->db->table('ar_actions')->where('invoice_id', $invoiceId)->orderBy('id')->get()->getResultArray();
    }

    /**
     * Valid details for a draft that can be submitted once a PDF is attached.
     *
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    protected function arDetails(int $companyId, int $typeId, array $over = []): array
    {
        static $n = 0;
        $n++;

        return $over + [
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'customer' => 'Harbour Foods', 'invoice_number' => 'AR-' . $n,
            'invoice_date' => '2026-10-01', 'due_date' => '2026-10-31', 'amount' => '250.00', 'currency' => 'USD',
        ];
    }
}
