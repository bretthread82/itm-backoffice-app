<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base for tests that need the real schema (all migrations, in-memory SQLite).
 */
abstract class AppTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    protected function makeUser(string $name, bool $admin = false, bool $active = true): int
    {
        $this->db->table('users')->insert([
            'username'     => strtolower(str_replace(' ', '.', $name)),
            'display_name' => $name,
            'is_admin'     => $admin ? 1 : 0,
            'active'       => $active ? 1 : 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    protected function makeCompany(string $code, ?int $groupId = null): int
    {
        $this->db->table('companies')->insert(['code' => $code, 'name' => $code, 'group_id' => $groupId]);

        return (int) $this->db->insertID();
    }

    protected function join(int $userId, int $companyId, bool $canSubmit = false, bool $canApprove = true): void
    {
        $this->db->table('user_companies')->insert([
            'user_id' => $userId, 'company_id' => $companyId, 'can_submit' => $canSubmit ? 1 : 0, 'can_approve' => $canApprove ? 1 : 0,
        ]);
    }

    /** @var list<string> files created by a test, removed in tearDown */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $f) {
            @unlink($f);
        }
        $this->createdFiles = [];
        parent::tearDown();
    }

    /** Makes an active user with an email address and returns the id. */
    protected function makeUserWithEmail(string $name, string $email, bool $admin = false): int
    {
        $id = $this->makeUser($name, $admin);
        $this->db->table('auth_identities')->insert([
            'user_id' => $id, 'type' => 'email_password', 'secret' => strtolower($email), 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** A tiny but valid PDF on disk (deleted automatically after the test). */
    protected function pdfFile(string $body = 'hello'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inv') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% {$body}\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $this->createdFiles[] = $path;

        return $path;
    }

    /** A temp file holding $bytes (deleted automatically after the test). */
    protected function tempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tst');
        file_put_contents($path, $bytes);
        $this->createdFiles[] = $path;

        return $path;
    }

    /** A valid 1x1 PNG. */
    protected static function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    /** Remember an app-stored invoice file so it is removed after the test. */
    protected function trackStoredFiles(): void
    {
        foreach ($this->db->table('invoice_files')->get()->getResultArray() as $row) {
            $this->createdFiles[] = \App\Services\InvoiceFiles::directory() . '/' . $row['stored_name'];
        }
        foreach ($this->db->table('invoice_documents')->get()->getResultArray() as $row) {
            $this->createdFiles[] = \App\Services\InvoiceDocuments::directory() . '/' . $row['stored_name'];
        }
    }

    /**
     * @param list<array{type?: string, user?: int|null, kind?: string, label?: string, email?: string|null, role?: string}> $steps
     *
     * @return array{type: int, route: int}
     */
    protected function makeRoute(int $companyId, array $steps, string $typeName = 'Standard', string $variant = 'default', bool $active = true, string $trigger = 'none', ?string $triggerValue = null): array
    {
        $type = $this->db->table('invoice_types')->where(['company_id' => $companyId, 'name' => $typeName])->get()->getRowArray();
        if ($type === null) {
            $this->db->table('invoice_types')->insert(['company_id' => $companyId, 'name' => $typeName, 'active' => 1]);
            $typeId = (int) $this->db->insertID();
        } else {
            $typeId = (int) $type['id'];
        }

        $this->db->table('routes')->insert([
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'variant' => $variant, 'variant_trigger' => $trigger,
            'trigger_value' => $triggerValue, 'version' => 1, 'active' => $active ? 1 : 0,
        ]);
        $routeId = (int) $this->db->insertID();

        foreach ($steps as $i => $s) {
            $titleId = null;
            if (! empty($s['role'])) {
                $row = $this->db->table('job_titles')->where('name', $s['role'])->get()->getRowArray();
                if ($row === null) {
                    $this->db->table('job_titles')->insert(['name' => $s['role']]);
                    $titleId = (int) $this->db->insertID();
                } else {
                    $titleId = (int) $row['id'];
                }
            }
            $this->db->table('route_steps')->insert([
                'route_id' => $routeId, 'step_order' => $i + 1, 'step_type' => $s['type'] ?? 'approve', 'job_title_id' => $titleId,
                'assignee_kind' => $s['kind'] ?? (isset($s['team']) ? 'team' : 'user'), 'assignee_user_id' => $s['user'] ?? null, 'team_id' => $s['team'] ?? null,
                'assignee_label' => $s['label'] ?? null, 'assignee_email' => $s['email'] ?? null, 'sla_days' => $s['sla'] ?? null,
            ]);
        }

        return ['type' => $typeId, 'route' => $routeId];
    }

    /**
     * Makes a team and puts the given users on it. Returns the team id.
     *
     * @param list<int> $members
     */
    protected function makeTeam(string $name, array $members = [], bool $active = true, ?string $groupEmail = null): int
    {
        $this->db->table('teams')->insert(['name' => $name, 'group_email' => $groupEmail, 'active' => $active ? 1 : 0, 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $this->db->insertID();
        foreach ($members as $userId) {
            $this->addToTeam($id, $userId);
        }

        return $id;
    }

    protected function addToTeam(int $teamId, int $userId): void
    {
        $this->db->table('team_members')->insert(['team_id' => $teamId, 'user_id' => $userId]);
    }

    /** @return array<string, mixed> */
    protected function invoiceRow(int $id): array
    {
        return $this->db->table('invoices')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<array<string, mixed>> */
    protected function actions(int $invoiceId): array
    {
        return $this->db->table('invoice_actions')->where('invoice_id', $invoiceId)->orderBy('id')->get()->getResultArray();
    }

    protected function makeInvoice(int $companyId, int $submittedBy, string $status = 'in_approval'): int
    {
        $this->db->table('invoice_types')->insert(['company_id' => $companyId, 'name' => 'Type ' . $companyId . random_int(1, 9999)]);
        $typeId = (int) $this->db->insertID();

        $this->db->table('invoices')->insert([
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'submitted_by' => $submittedBy,
            'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }
}
