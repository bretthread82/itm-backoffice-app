<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Routing\RoutingNormalizer;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Uses rows taken from the real Approval Routing export (tests/_support/fixtures).
 *
 * @internal
 */
final class RoutingNormalizerTest extends CIUnitTestCase
{
    /** @var array{routes: array<string, array<string, mixed>>, issues: list<array<string, mixed>>, stats: array<string, int>, has_emails: bool} */
    private array $result;

    protected function setUp(): void
    {
        parent::setUp();
        $sheet        = IOFactory::load(SUPPORTPATH . 'fixtures/routing_sample.csv')->getActiveSheet()->toArray(null, true, true, false);
        $this->result = (new RoutingNormalizer())->normalise($sheet);
    }

    private function route(string $company, string $type, string $variant = 'default'): array
    {
        $key = RoutingNormalizer::companyCode($company) . '|' . strtolower($type) . '|' . $variant;
        $this->assertArrayHasKey($key, $this->result['routes'], 'Missing route ' . $key);

        return $this->result['routes'][$key];
    }

    /** @return list<string> */
    private function messages(string $level): array
    {
        return array_column(array_filter($this->result['issues'], static fn (array $i): bool => $i['level'] === $level), 'message');
    }

    private function anyMessageContains(string $level, string $needle): bool
    {
        foreach ($this->messages($level) as $m) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function testTestDataIsExcluded(): void
    {
        $companies = array_column($this->result['routes'], 'company_code');
        $this->assertNotContains('COMPANYB', $companies);
        $types = array_map('strtolower', array_column($this->result['routes'], 'type_name'));
        $this->assertNotContains('testing launch', $types);
        $this->assertNotContains('test_invoice_imagine', $types);
        $this->assertTrue($this->anyMessageContains('info', 'Test data skipped'));
    }

    public function testStepsAreOrderedAndRenumbered(): void
    {
        $route = $this->route('HCSRL', 'Accountant Flow');
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($route['steps'], 'order'));
        $this->assertSame('Central Processing Clerk 1', $route['steps'][0]['role']);
        $this->assertSame('AP Processing Clerk', $route['steps'][5]['role']);
    }

    public function testHcRouteIsCanonicalAndLegacyIsArchived(): void
    {
        $legacy = $this->route('HCSRL', 'Gym');
        $hc     = $this->route('HCSRL', 'HC Gym');

        $this->assertTrue($legacy['archived']);
        $this->assertFalse($legacy['active']);
        $this->assertFalse($hc['archived']);
        $this->assertTrue($hc['active']);
        $this->assertCount(5, $hc['steps']);
    }

    public function testHcSrlTypesWithoutHcEquivalentStayActive(): void
    {
        foreach (['Property - Roger Kelly', 'PCL Shipment Invoices'] as $type) {
            $route = $this->route('HCSRL', $type);
            $this->assertFalse($route['archived'], $type);
            $this->assertTrue($route['active'], $type);
        }
    }

    public function testYesVariantsAreInactiveExtraApprovalRoutes(): void
    {
        $base    = $this->route('Imagine', 'Procurement');
        $variant = $this->route('Imagine', 'Procurement', 'extra_approval');

        $this->assertTrue($base['active']);
        $this->assertFalse($variant['active']);
        $this->assertSame('Procurement', $variant['type_name']);
        $this->assertCount(4, $base['steps']);
        $this->assertCount(5, $variant['steps']);
        $this->assertSame('Group Treasurer', $variant['steps'][3]['role']);

        $rhc = $this->route('RHC', 'Admin Invoice RHC', 'extra_approval');
        $this->assertFalse($rhc['active']);
    }

    public function testBlankCompanyIsInferredAndDuplicateStepDropped(): void
    {
        $route = $this->route('HCSRL', 'HC Projects - Project Manager & Senior Accounts Supervisor');

        $this->assertCount(6, $route['steps']); // not 7: the duplicate step 6 is dropped
        $this->assertFalse($route['blocked']);
        $this->assertTrue($this->anyMessageContains('warning', 'Blank company filled in'));
        $this->assertTrue($this->anyMessageContains('warning', 'Duplicate step 6 dropped'));
    }

    public function testTyposAreCorrected(): void
    {
        $route = $this->route('HCSRL', 'Projects - Project Manager');
        $roles = array_column($route['steps'], 'role');

        $this->assertContains('General Manager', $roles);
        $this->assertNotContains('Gneral Manager', $roles);
        $this->assertSame('Central Processing Clerk 1', $roles[0]);
        $this->assertTrue($this->anyMessageContains('warning', 'Typo corrected'));
    }

    public function testUnusualButUncertainRoleIsFlaggedNotChanged(): void
    {
        $roles = array_column($this->route('Mjet', 'Mjet Fuel')['steps'], 'role');

        $this->assertContains('Accountant Mjet APClerk', $roles);
        $this->assertTrue($this->anyMessageContains('warning', 'Unusual role "Accountant Mjet APClerk"'));
    }

    public function testBlankRoleUsesPersonAndMailboxIsTeamKind(): void
    {
        $route = $this->route('Mjet', 'Mjet Advertising & Marketing');
        $first = $route['steps'][0];

        $this->assertSame('Central Processing Clerk 1', $first['role']);
        $this->assertSame('team', $first['assignee_kind']);
        $this->assertSame('submit', $first['step_type']);
        $this->assertTrue($this->anyMessageContains('warning', 'Role was blank'));
    }

    public function testCommasInQuotedTypeNamesSurvive(): void
    {
        $this->route('Mjet', 'Mjet Airport Fees (Departure Tax, Terminal Fees, Parking & Landing)');
        $this->addToAssertionCount(1);
    }

    public function testRowWithNoRoleOrderNeedsAttentionAndIsNotImported(): void
    {
        $route = $this->route('Bushy Park', 'BP Workshop');

        $this->assertCount(4, $route['steps']);
        $this->assertNotContains('Invoice Tracking', array_column($route['steps'], 'role'));
        $this->assertTrue($this->anyMessageContains('attention', 'no role order'));
    }

    public function testExternalPartyAndTeamKinds(): void
    {
        $steps = $this->route('Imagine', 'Procurement')['steps'];

        $this->assertSame('team', $steps[0]['assignee_kind']); // Imagine Finance
        $this->assertSame('external', $steps[2]['assignee_kind']);       // Aspireqs
        $this->assertSame('user', $steps[1]['assignee_kind']);           // Rocio Montero
    }

    public function testStepTypesAreInferredFromPosition(): void
    {
        $steps = $this->route('HCSRL', 'HC Gym')['steps'];

        $this->assertSame('submit', $steps[0]['step_type']);   // AP Clerk
        $this->assertSame('approve', $steps[2]['step_type']);  // Accountant
        $this->assertSame('process', $steps[4]['step_type']);  // AP Processing Clerk
    }

    public function testRoleOrderGapsAreRenumbered(): void
    {
        // The Bushy Park route lost its Invoice Tracking row but still has contiguous 1..4.
        $this->assertSame([1, 2, 3, 4], array_column($this->route('Bushy Park', 'BP Workshop')['steps'], 'order'));
        // Property - Roger Kelly has two clerk steps; both are kept (different orders).
        $this->assertCount(3, $this->route('HCSRL', 'Property - Roger Kelly')['steps']);
    }

    public function testNoEmailColumnIsReported(): void
    {
        $this->assertFalse($this->result['has_emails']);
    }

    public function testEmailsAreReadWhenTheColumnExists(): void
    {
        $sheet = [
            ['Company', 'Invoice Type', 'Role Order', 'Role', 'Person', 'Person Email'],
            ['HCSRL', 'Gym', '1', 'AP Clerk', 'Annie Prince', 'Annie.Prince@Example.com'],
            ['HCSRL', 'Gym', '2', 'Accountant', 'Cherisse  Depeiza', ''],
        ];
        $out = (new RoutingNormalizer())->normalise($sheet);

        $this->assertTrue($out['has_emails']);
        $steps = $out['routes']['HCSRL|gym|default']['steps'];
        $this->assertSame('annie.prince@example.com', $steps[0]['email']);
        $this->assertSame('', $steps[1]['email']);
        $this->assertSame('Cherisse Depeiza', $steps[1]['person']); // whitespace collapsed
    }

    public function testConflictingStepsBlockTheRoute(): void
    {
        $sheet = [
            ['Company', 'Invoice Type', 'Role Order', 'Role', 'Person'],
            ['CHL', 'Admin', '1', 'Accounts Clerk', 'A Person'],
            ['CHL', 'Admin', '2', 'Manager', 'B Person'],
            ['CHL', 'Admin', '2', 'Director', 'C Person'],
        ];
        $out = (new RoutingNormalizer())->normalise($sheet);

        $this->assertTrue($out['routes']['CHL|admin|default']['blocked']);
        $this->assertSame(1, $out['stats']['blocked']);
    }

    public function testCompanyCodes(): void
    {
        $this->assertSame('BUSHY_PARK', RoutingNormalizer::companyCode('Bushy Park'));
        $this->assertSame('CPSI_CT_DT', RoutingNormalizer::companyCode('CPSI_CT_DT'));
        $this->assertSame('HCSRL', RoutingNormalizer::companyCode(' hcsrl '));
    }

    public function testCompanyCountMatchesTheTenRealCompaniesInTheSample(): void
    {
        $codes = array_unique(array_column($this->result['routes'], 'company_code'));
        sort($codes);
        $this->assertSame(['BUSHY_PARK', 'CHL', 'HCSRL', 'IMAGINE', 'MERIDIAN', 'MJET', 'PCL', 'RHC', 'VESL'], $codes);
    }
}
