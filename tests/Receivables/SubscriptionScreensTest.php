<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\SubscriptionService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * The subscription screens through the full filter pipeline: they render for someone with access, show only the
 * user's companies, and changing one needs the per-company right.
 *
 * @internal
 */
final class SubscriptionScreensTest extends ReceivablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $company;
    private int $other;
    private int $raiser;
    private int $viewer;
    private int $type;
    private int $mine;
    private int $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->company = $this->makeCompany('ACME');
        $this->other   = $this->makeCompany('OTHER');
        $this->raiser  = $this->makeUserNamed('Rae Raiser', 'rae@example.test');
        $this->viewer  = $this->makeUserNamed('Vic Viewer', 'vic@example.test');
        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->viewer, $this->company, []);
        foreach ([$this->raiser, $this->viewer] as $u) {
            $this->db->table('user_roles')->insert(['user_id' => $u, 'role_id' => $this->roleId('receivables_user')]);
            service('permissions')->forget($u);
        }

        $this->type = $this->makeArRoute($this->company, [['type' => 'issue', 'user' => $this->raiser]])['type'];
        $otherType  = $this->makeArRoute($this->other, [['type' => 'issue', 'user' => $this->raiser]])['type'];

        $service      = new SubscriptionService($this->db);
        $this->mine   = $service->create($this->raiser, $this->details($this->company, $this->type, 'Harbour Foods'));
        $this->grant($this->raiser, $this->other, ['submit']);
        $this->hidden = $service->create($this->raiser, $this->details($this->other, $otherType, 'Secret Customer'));
        $this->db->table('user_companies')->where(['user_id' => $this->raiser, 'company_id' => $this->other])->delete();
        $this->db->table('user_company_capabilities')->where(['user_id' => $this->raiser, 'company_id' => $this->other])->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function details(int $companyId, int $typeId, string $customer): array
    {
        return [
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'customer' => $customer, 'description' => 'Hosting', 'amount' => '99.00',
            'currency' => 'USD', 'frequency' => 'monthly', 'next_bill_date' => date('Y-m-d', strtotime('+20 days')), 'payment_terms_days' => 30,
        ];
    }

    /** One fresh request as a signed-in user (Shield caches its state per request). */
    private function as(int $userId, string $method, string $path, ?array $params = null)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();

        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        $call = $this->withSession(['user' => ['id' => $userId]]);

        return $method === 'post' ? $call->post($path, ($params ?? []) + [csrf_token() => csrf_hash()]) : $call->get($path);
    }

    public function testTheRegisterShowsOnlyYourCompanies(): void
    {
        $result = $this->as($this->raiser, 'get', 'receivables/subscriptions');

        $this->assertTrue($result->isOK(), 'status ' . $result->response()->getStatusCode());
        $result->assertSee('Harbour Foods');
        $result->assertDontSee('Secret Customer');
        $result->assertSee('New subscription');
    }

    public function testASubscriptionInAnotherCompanyIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->as($this->raiser, 'get', 'receivables/subscriptions/' . $this->hidden);
    }

    public function testSomeoneWithoutTheRaiseRightCanSeeButNotChange(): void
    {
        $page = $this->as($this->viewer, 'get', 'receivables/subscriptions/' . $this->mine);
        $this->assertTrue($page->isOK());
        $page->assertDontSee('End subscription');

        $this->as($this->viewer, 'post', 'receivables/subscriptions/' . $this->mine . '/pause');
        $this->assertSame('active', $this->db->table('ar_subscriptions')->where('id', $this->mine)->get()->getRow()->status);
    }

    public function testARaiserCanSetUpPauseAndEndOne(): void
    {
        $this->as($this->raiser, 'post', 'receivables/subscriptions', $this->details($this->company, $this->type, 'New Customer'));
        $row = $this->db->table('ar_subscriptions')->where('customer', 'New Customer')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame($this->raiser, (int) $row['owner_id']);

        $this->as($this->raiser, 'post', 'receivables/subscriptions/' . $row['id'] . '/pause');
        $this->assertSame('paused', $this->db->table('ar_subscriptions')->where('id', $row['id'])->get()->getRow()->status);

        $this->as($this->raiser, 'post', 'receivables/subscriptions/' . $row['id'] . '/end');
        $this->assertSame('ended', $this->db->table('ar_subscriptions')->where('id', $row['id'])->get()->getRow()->status);
    }

    public function testTheFormRendersAndTheSidebarLinksToSubscriptions(): void
    {
        $form = $this->as($this->raiser, 'get', 'receivables/subscriptions/new');
        $this->assertTrue($form->isOK(), 'status ' . $form->response()->getStatusCode());
        $form->assertSee('Set up subscription');
        $form->assertSee('receivables/subscriptions');
    }
}
