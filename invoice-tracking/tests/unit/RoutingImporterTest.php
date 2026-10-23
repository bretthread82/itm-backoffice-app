<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\UserModel;
use App\Services\Routing\RoutingImporter;
use RuntimeException;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class RoutingImporterTest extends AppTestCase
{
    private RoutingImporter $importer;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new RoutingImporter($this->db);
        $this->adminId  = $this->makeUser('Admin', true);
    }

    private function sample(): string
    {
        return SUPPORTPATH . 'fixtures/routing_sample.csv';
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt') . '.csv';
        file_put_contents($path, $body);

        return $path;
    }

    public function testAnalyseWritesNothing(): void
    {
        $analysis = $this->importer->analyse($this->sample());

        $this->assertGreaterThan(0, $analysis['stats']['routes']);
        $this->assertSame(0, $this->db->table('companies')->countAllResults());
        $this->assertSame(0, $this->db->table('routes')->countAllResults());
        $this->assertSame(1, $this->db->table('users')->countAllResults()); // only the admin
    }

    public function testCommitCreatesCompaniesTypesRoutesAndSteps(): void
    {
        $summary = $this->importer->commit($this->sample(), $this->adminId);

        $this->assertSame(9, $summary['companies_created']);
        $this->assertSame($summary['routes_created'], $this->db->table('routes')->countAllResults());
        $this->assertSame($summary['steps'], $this->db->table('route_steps')->countAllResults());
        $this->assertSame(0, $summary['routes_blocked']);

        $company = $this->db->table('companies')->where('code', 'BUSHY_PARK')->get()->getRowArray();
        $this->assertSame('Bushy Park', $company['name']);
        $this->assertNull($company['group_id']);
    }

    public function testLegacyAndVariantRoutesAreInactiveHcRoutesActive(): void
    {
        $this->importer->commit($this->sample(), $this->adminId);

        $route = function (string $company, string $type, string $variant = 'default'): array {
            return $this->db->query(
                'SELECT r.* FROM routes r JOIN companies c ON c.id = r.company_id JOIN invoice_types t ON t.id = r.invoice_type_id
                 WHERE c.code = ? AND t.name = ? AND r.variant = ?',
                [$company, $type, $variant],
            )->getRowArray();
        };

        $this->assertSame(1, (int) $route('HCSRL', 'HC Gym')['active']);
        $this->assertSame(0, (int) $route('HCSRL', 'Gym')['active']);            // legacy copy
        $this->assertSame(1, (int) $route('HCSRL', 'Property - Roger Kelly')['active']);
        $yes = $route('IMAGINE', 'Procurement', 'extra_approval');
        $this->assertSame(0, (int) $yes['active']);
        $this->assertSame('none', $yes['variant_trigger']);

        $legacyType = $this->db->table('invoice_types')->where('name', 'Gym')->get()->getRowArray();
        $this->assertSame(0, (int) $legacyType['active']);
    }

    public function testStepsStoreKindsUsersAndLabels(): void
    {
        $this->importer->commit($this->sample(), $this->adminId);

        $steps = $this->db->query(
            "SELECT s.* FROM route_steps s JOIN routes r ON r.id = s.route_id JOIN invoice_types t ON t.id = r.invoice_type_id
             WHERE t.name = 'Procurement' AND r.variant = 'default' ORDER BY s.step_order",
        )->getResultArray();

        $this->assertSame(['team', 'user', 'external', 'team'], array_column($steps, 'assignee_kind'));
        $this->assertNull($steps[0]['assignee_user_id']);
        $this->assertSame('Imagine Finance', $steps[0]['assignee_label']);
        // A shared mailbox in the sheet becomes a team with no members.
        $this->assertSame('Imagine Finance', $this->db->table('teams')->where('id', $steps[0]['team_id'])->get()->getRowArray()['name']);
        $this->assertSame(0, $this->db->table('team_members')->where('team_id', $steps[0]['team_id'])->countAllResults());
        $this->assertNotNull($steps[1]['assignee_user_id']);
        $this->assertSame(['submit', 'approve', 'approve', 'process'], array_column($steps, 'step_type'));
    }

    public function testPeopleWithoutEmailBecomeInactivePlaceholdersAndAreReused(): void
    {
        $summary = $this->importer->commit($this->sample(), $this->adminId);

        $this->assertGreaterThan(0, $summary['placeholders_created']);
        $this->assertSame(0, $summary['users_created']);

        $annie = $this->db->table('users')->where('display_name', 'Annie Prince')->get()->getResultArray();
        $this->assertCount(1, $annie); // one account no matter how many routes name her
        $this->assertSame(0, (int) $annie[0]['active']);
        $this->assertSame(1, (int) $annie[0]['email_missing']);
        $this->assertSame(0, $this->db->table('auth_identities')->where('user_id', $annie[0]['id'])->countAllResults()); // cannot sign in

        // "Ariel  Wood" (double space) and "Ariel Wood" are the same person.
        $this->assertSame(1, $this->db->table('users')->like('display_name', 'Ariel')->countAllResults());
        // Shared mailboxes and outside parties never become users.
        $this->assertSame(0, $this->db->table('users')->where('display_name', 'Imagine Finance')->countAllResults());
        $this->assertSame(0, $this->db->table('users')->where('display_name', 'Aspireqs')->countAllResults());
    }

    public function testRerunSkipsExistingRoutesAndOverwriteReplacesStepsButKeepsActivation(): void
    {
        $this->importer->commit($this->sample(), $this->adminId);
        $routesBefore = $this->db->table('routes')->countAllResults();

        $again = $this->importer->commit($this->sample(), $this->adminId);
        $this->assertSame(0, $again['routes_created']);
        $this->assertSame($routesBefore, $again['routes_skipped_existing']);

        // An admin activated a variant once its trigger was defined.
        $this->db->table('routes')->where('variant', 'extra_approval')->update(['active' => 1, 'variant_trigger' => 'manual_flag']);

        $over = $this->importer->commit($this->sample(), $this->adminId, true);
        $this->assertSame($routesBefore, $over['routes_updated']);
        $this->assertSame(0, $this->db->table('routes')->where('variant', 'extra_approval')->where('active', 0)->countAllResults());
        $this->assertSame(0, $this->db->table('routes')->where('variant', 'extra_approval')->where('variant_trigger', 'none')->countAllResults());
        $this->assertSame($routesBefore, $this->db->table('routes')->countAllResults());
    }

    public function testUpdatedListWithEmailsFillsPlaceholdersWithoutReworkingRoutes(): void
    {
        $first = $this->csv("Company,Invoice Type,Role Order,Role,Person\nCHL,Admin,1,Accounts Clerk,Ann Example\nCHL,Admin,2,Manager,Bob Sample\n");
        $this->importer->commit($first, $this->adminId);

        $ann   = (int) $this->db->table('users')->where('display_name', 'Ann Example')->get()->getRow()->id;
        $stepA = $this->db->table('route_steps')->where('assignee_user_id', $ann)->get()->getRowArray();
        $this->assertNotNull($stepA);

        $second = $this->csv("Company,Invoice Type,Role Order,Role,Person,Person Email\nCHL,Admin,1,Accounts Clerk,Ann  Example,Ann@Example.test\nCHL,Admin,2,Manager,Bob Sample,bob@example.test\nCHL,Admin2,1,Clerk,New Person,new@example.test\n");
        $summary = $this->importer->commit($second, $this->adminId, true);

        $this->assertSame(2, $summary['emails_filled']);
        $this->assertSame(1, $summary['users_created']);

        $user = model(UserModel::class)->findByCredentials(['email' => 'ann@example.test']);
        $this->assertNotNull($user);
        $this->assertSame($ann, (int) $user->id);       // same account, now with an email
        $this->assertSame(1, (int) $user->active);
        $this->assertSame(0, (int) $user->email_missing);

        // The step still points at the same user, so no route rework is needed.
        $this->assertSame($ann, (int) $this->db->table('route_steps')->where('assignee_label', 'Ann Example')->get()->getRow()->assignee_user_id);
        $this->assertSame(1, $this->db->table('users')->where('display_name', 'Ann Example')->countAllResults());
    }

    public function testBlockedRoutesAreNotImported(): void
    {
        $file = $this->csv("Company,Invoice Type,Role Order,Role,Person\nCHL,Good,1,Clerk,A One\nCHL,Good,2,Boss,B Two\nCHL,Bad,1,Clerk,A One\nCHL,Bad,2,Boss,B Two\nCHL,Bad,2,Other,C Three\n");

        $summary = $this->importer->commit($file, $this->adminId);

        $this->assertSame(1, $summary['routes_created']);
        $this->assertSame(1, $summary['routes_blocked']);
        $this->assertSame(0, $this->db->table('invoice_types')->where('name', 'Bad')->countAllResults());
    }

    public function testNonAdminCannotCommit(): void
    {
        $plain = $this->makeUser('Plain');

        $this->expectException(RuntimeException::class);
        $this->importer->commit($this->sample(), $plain);
    }

    public function testNothingImportableIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->importer->commit($this->csv("Company,Invoice Type,Role Order,Role,Person\nCompanyB,Type1,1,SubmitterB,Support\n"), $this->adminId);
    }

    public function testExistingCompanyIsMatchedByNameNotDuplicated(): void
    {
        $this->db->table('companies')->insert(['code' => 'BP', 'name' => 'Bushy Park', 'active' => 1]);

        $this->importer->commit($this->sample(), $this->adminId);

        $this->assertSame(1, $this->db->table('companies')->where('LOWER(name)', 'bushy park')->countAllResults());
        $this->assertSame(0, $this->db->table('companies')->where('code', 'BUSHY_PARK')->countAllResults());
    }
}
