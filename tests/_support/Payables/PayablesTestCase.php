<?php

declare(strict_types=1);

namespace Tests\Support\Payables;

use App\Core\Payables\InvoiceDocuments;
use App\Core\Payables\InvoiceFiles;
use Config\Services;
use Tests\Support\Mfa\MfaTestCase;

/**
 * Fresh schema (all migrations), seeded roles, a capturing fake mailer, and builders for companies,
 * memberships, routes, teams and invoices. Users are real platform users (UserService), so names and
 * the active flag come from staff profiles, exactly as in the running app.
 */
abstract class PayablesTestCase extends MfaTestCase
{
    /** @var list<string> files created by a test, removed in tearDown */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('fileStore');
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();

        foreach ($this->createdFiles as $f) {
            @unlink($f);
        }

        $this->createdFiles = [];
        Services::resetSingle('fileStore');
        parent::tearDown();
    }

    /** Creates an active user; $admin gives the platform admin role, $active=false deactivates the profile. */
    protected function makeUserNamed(string $name, ?string $email = null, bool $admin = false, bool $active = true): int
    {
        static $n = 0;
        $n++;

        [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'User');
        $email ??= strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name)) . $n . '@example.test';

        $id = (new \App\Core\UserService())->create(
            ['email' => $email, 'first_name' => $first, 'last_name' => $last],
            $admin ? [$this->roleId('admin')] : [],
        )['id'];
        $this->mailer->sent = [];

        if (! $active) {
            $this->db->table('staff_profiles')->where('user_id', $id)->update(['status' => 'inactive']);
        }

        return $id;
    }

    protected function makeUserWithEmail(string $name, string $email, bool $admin = false): int
    {
        return $this->makeUserNamed($name, $email, $admin);
    }

    protected function setActive(int $userId, bool $active): void
    {
        $this->db->table('staff_profiles')->where('user_id', $userId)->update(['status' => $active ? 'active' : 'inactive']);
    }

    protected function makeCompany(string $code, ?int $groupId = null): int
    {
        $this->db->table('companies')->insert(['code' => $code, 'name' => $code, 'group_id' => $groupId, 'active' => 1]);

        return (int) $this->db->insertID();
    }

    protected function join(int $userId, int $companyId, bool $canSubmit = false, bool $canApprove = true): void
    {
        $this->db->table('user_companies')->insert([
            'user_id' => $userId, 'company_id' => $companyId, 'can_submit' => $canSubmit ? 1 : 0, 'can_approve' => $canApprove ? 1 : 0,
        ]);
    }

    protected function grantGroupLevel(int $userId, ?int $by = null): void
    {
        service('memberships')->grantGroupLevel($userId, $by);
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

    /** Remember app-stored invoice files so they are removed after the test. */
    protected function trackStoredFiles(): void
    {
        try {
            foreach ($this->db->table('inv_files')->get()->getResultArray() as $row) {
                $this->createdFiles[] = InvoiceFiles::directory() . '/' . $row['stored_name'];
            }

            foreach ($this->db->table('inv_documents')->get()->getResultArray() as $row) {
                $this->createdFiles[] = InvoiceDocuments::directory() . '/' . $row['stored_name'];
            }
        } catch (\Throwable) {
            // The schema may already be gone.
        }
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array{type: int, route: int}
     */
    protected function makeRoute(int $companyId, array $steps, string $typeName = 'Standard', string $variant = 'default', bool $active = true, string $trigger = 'none', ?string $triggerValue = null): array
    {
        $type = $this->db->table('inv_invoice_types')->where(['company_id' => $companyId, 'name' => $typeName])->get()->getRowArray();

        if ($type === null) {
            $this->db->table('inv_invoice_types')->insert(['company_id' => $companyId, 'name' => $typeName, 'active' => 1]);
            $typeId = (int) $this->db->insertID();
        } else {
            $typeId = (int) $type['id'];
        }

        $this->db->table('inv_routes')->insert([
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'variant' => $variant, 'variant_trigger' => $trigger,
            'trigger_value' => $triggerValue, 'version' => 1, 'active' => $active ? 1 : 0,
        ]);
        $routeId = (int) $this->db->insertID();

        foreach ($steps as $i => $s) {
            $titleId = null;

            if (! empty($s['role'])) {
                $row = $this->db->table('inv_job_titles')->where('name', $s['role'])->get()->getRowArray();

                if ($row === null) {
                    $this->db->table('inv_job_titles')->insert(['name' => $s['role']]);
                    $titleId = (int) $this->db->insertID();
                } else {
                    $titleId = (int) $row['id'];
                }
            }

            $this->db->table('inv_route_steps')->insert([
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
    protected function makeTeam(string $name, array $members = [], bool $active = true, ?string $groupEmail = null): int
    {
        $this->db->table('inv_teams')->insert(['name' => $name, 'group_email' => $groupEmail, 'active' => $active ? 1 : 0, 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $this->db->insertID();

        foreach ($members as $userId) {
            $this->addToTeam($id, $userId);
        }

        return $id;
    }

    protected function addToTeam(int $teamId, int $userId): void
    {
        $this->db->table('inv_team_members')->insert(['team_id' => $teamId, 'user_id' => $userId]);
    }

    /** @return array<string, mixed> */
    protected function invoiceRow(int $id): array
    {
        return $this->db->table('inv_invoices')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<array<string, mixed>> */
    protected function actions(int $invoiceId): array
    {
        return $this->db->table('inv_actions')->where('invoice_id', $invoiceId)->orderBy('id')->get()->getResultArray();
    }

    protected function makeInvoice(int $companyId, int $submittedBy, string $status = 'in_approval'): int
    {
        $this->db->table('inv_invoice_types')->insert(['company_id' => $companyId, 'name' => 'Type ' . $companyId . random_int(1, 999999)]);
        $typeId = (int) $this->db->insertID();

        $this->db->table('inv_invoices')->insert([
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'submitted_by' => $submittedBy,
            'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Emails the fake mailer captured for an address, as objects with to / subject / html.
     *
     * @return list<object>
     */
    protected function mailTo(string $email): array
    {
        return array_values(array_map(
            static fn (array $m): object => (object) $m,
            array_filter($this->mailer->sent, static fn (array $m): bool => strtolower($m['to']) === strtolower($email)),
        ));
    }

    /** In-app notifications a user holds for an event. */
    protected function bell(int $userId, string $event): int
    {
        return $this->db->table('notifications')->where(['user_id' => $userId, 'type' => $event])->countAllResults();
    }
}
