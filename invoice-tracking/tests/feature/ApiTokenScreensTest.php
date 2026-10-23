<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Shield\Entities\User;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class ApiTokenScreensTest extends FeatureTestCase
{
    private User $admin;
    private User $alan;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->shieldUser('admin@example.test', 'Admin', true);
        $this->alan  = $this->shieldUser('alan@example.test', 'Alan');
        $this->bob   = $this->shieldUser('bob@example.test', 'Bob');
        $this->join((int) $this->alan->id, $this->makeCompany('ACME'));
    }

    /** Posts the admin "create token" form as $as; user_id defaults to Alan. */
    private function create(User $as, array $post): string
    {
        return (string) $this->actingAs($as)->post('admin/api-tokens', $post + ['user_id' => (string) $this->alan->id])->response()->getBody();
    }

    private function rawFrom(string $body): string
    {
        $this->assertSame(1, preg_match('/id="new-token">([A-Za-z0-9]{64})</', $body, $m), 'the new token should be shown on the page');

        return $m[1];
    }

    private function audit(string $event): int
    {
        return $this->db->table('audit_log')->where('event', $event)->countAllResults();
    }

    public function testTheScreenListsOnlyMyTokens(): void
    {
        $this->alan->generateAccessToken('Alan report', ['api.read']);
        $this->bob->generateAccessToken('Bob secret system', ['api.read']);

        $body = (string) $this->actingAs($this->alan)->get('account/api-tokens')->getBody();

        $this->assertStringContainsString('Alan report', $body);
        $this->assertStringNotContainsString('Bob secret system', $body);
    }

    public function testCreatingATokenShowsItOnceStoresOnlyItsHashAndItWorks(): void
    {
        $body = $this->create($this->admin, ['name' => 'Power BI', 'days' => '90']);
        $raw  = $this->rawFrom($body);

        $row = $this->db->table('auth_identities')->where('type', 'access_token')->where('user_id', $this->alan->id)->get()->getRowArray();
        $this->assertSame('Power BI', $row['name']);
        $this->assertSame(hash('sha256', $raw), $row['secret']);
        $this->assertStringNotContainsString($raw, json_encode($row));
        $this->assertSame(['api.read'], unserialize($row['extra']));
        $this->assertGreaterThan(time() + 80 * 86400, strtotime((string) $row['expires']));
        $this->assertSame(1, $this->audit('api_token.created'));
        $this->assertStringNotContainsString($raw, json_encode($this->db->table('audit_log')->get()->getResultArray()));

        // Gone from the next page view: it is shown once only.
        $this->assertStringNotContainsString($raw, (string) $this->actingAs($this->alan)->get('account/api-tokens')->getBody());

        // And it really authenticates the API as Alan.
        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
        $me = json_decode((string) $this->withHeaders(['Authorization' => 'Bearer ' . $raw])->get('api/v1/me')->response()->getBody(), true);
        $this->assertSame((int) $this->alan->id, $me['data']['id']);
    }

    public function testBadInputAndTheTokenLimitAreRefused(): void
    {
        $this->create($this->admin, ['name' => '', 'days' => '90']);
        $this->create($this->admin, ['name' => 'x', 'days' => '12345']);
        $this->create($this->admin, ['name' => 'x', 'days' => '30', 'user_id' => '999999']);
        $this->assertSame(0, $this->db->table('auth_identities')->where('type', 'access_token')->countAllResults());

        for ($i = 0; $i < 10; $i++) {
            $this->alan->generateAccessToken('t' . $i, ['api.read']);
        }
        $this->create($this->admin, ['name' => 'eleventh', 'days' => '30']);
        $this->assertSame(10, $this->db->table('auth_identities')->where('type', 'access_token')->countAllResults());
    }

    public function testOnlyAdminsCanCreateTokens(): void
    {
        $this->actingAs($this->alan)->post('admin/api-tokens', ['user_id' => (string) $this->alan->id, 'name' => 'mine', 'days' => '30'])->assertRedirectTo('/');
        $this->assertSame(0, $this->db->table('auth_identities')->where('type', 'access_token')->countAllResults());

        // The old self-service route is gone, and the page no longer offers a form.
        try {
            $this->actingAs($this->alan)->post('account/api-tokens', ['name' => 'mine', 'days' => '30']);
            $this->fail('POST account/api-tokens should no longer exist.');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, $this->db->table('auth_identities')->where('type', 'access_token')->countAllResults());
        $page = (string) $this->actingAs($this->alan)->get('account/api-tokens')->getBody();
        $this->assertStringNotContainsString('Create token', $page);
        $this->assertStringContainsString('issued by an administrator', $page);
    }

    public function testAnAdminCanMakeATokenForAnotherUserAndForThemselves(): void
    {
        $raw = $this->rawFrom($this->create($this->admin, ['name' => 'For Bob', 'days' => '30', 'user_id' => (string) $this->bob->id]));

        $row = $this->db->table('auth_identities')->where('type', 'access_token')->get()->getRowArray();
        $this->assertSame((int) $this->bob->id, (int) $row['user_id']);
        $this->assertSame(hash('sha256', $raw), $row['secret']);

        $audit = $this->db->table('audit_log')->where('event', 'api_token.created')->get()->getRowArray();
        $detail = json_decode((string) $audit['detail'], true);
        $this->assertTrue($detail['for_other']);
        $this->assertSame((int) $this->admin->id, (int) $audit['user_id']);

        $this->create($this->admin, ['name' => 'For me', 'days' => '30', 'user_id' => (string) $this->admin->id]);
        $this->assertSame(1, $this->db->table('auth_identities')->where('type', 'access_token')->where('user_id', $this->admin->id)->countAllResults());
    }

    public function testATokenCannotBeMadeForAnInactiveUser(): void
    {
        $this->db->table('users')->where('id', $this->bob->id)->update(['active' => 0]);

        $this->create($this->admin, ['name' => 'x', 'days' => '30', 'user_id' => (string) $this->bob->id]);

        $this->assertSame(0, $this->db->table('auth_identities')->where('type', 'access_token')->countAllResults());
    }

    public function testIRevokeMyOwnTokenButNeverSomeoneElses(): void
    {
        $mine   = $this->alan->generateAccessToken('mine', ['api.read']);
        $theirs = $this->bob->generateAccessToken('theirs', ['api.read']);

        $this->actingAs($this->alan)->post('account/api-tokens/' . $theirs->id . '/revoke')->assertRedirect();
        $this->assertSame(1, $this->db->table('auth_identities')->where('id', $theirs->id)->countAllResults());
        $this->assertSame(0, $this->audit('api_token.revoked'));

        $this->actingAs($this->alan)->post('account/api-tokens/' . $mine->id . '/revoke')->assertRedirect();
        $this->assertSame(0, $this->db->table('auth_identities')->where('id', $mine->id)->countAllResults());
        $this->assertSame(1, $this->audit('api_token.revoked'));
    }

    public function testAdminsSeeEveryTokenAndCanRevokeButOthersCannotEvenLook(): void
    {
        $t = $this->bob->generateAccessToken('Bob system', ['api.read']);

        $page = (string) $this->actingAs($this->admin)->get('admin/api-tokens')->getBody();
        $this->assertStringContainsString('Bob system', $page);
        $this->assertStringContainsString('Bob', $page);

        $this->actingAs($this->alan)->get('admin/api-tokens')->assertRedirectTo('/');
        $this->actingAs($this->alan)->post('admin/api-tokens/' . $t->id . '/revoke')->assertRedirectTo('/');
        $this->assertSame(1, $this->db->table('auth_identities')->where('id', $t->id)->countAllResults());

        $this->actingAs($this->admin)->post('admin/api-tokens/' . $t->id . '/revoke')->assertRedirect();
        $this->assertSame(0, $this->db->table('auth_identities')->where('id', $t->id)->countAllResults());
        $this->assertSame(1, $this->audit('api_token.revoked'));
    }

    public function testDocsPageNeedsSignInAndLoadsSwaggerFromThisSite(): void
    {
        $this->get('api/docs')->assertRedirect();

        $body = (string) $this->actingAs($this->alan)->get('api/docs')->getBody();
        $this->assertStringContainsString('assets/vendor/swagger-ui/swagger-ui-bundle.js', $body);
        $this->assertStringContainsString('api/v1/openapi.json', $body);
        $this->assertStringNotContainsString('cdn.', $body);
        $this->assertFileExists(FCPATH . 'assets/vendor/swagger-ui/swagger-ui-bundle.js');
        $this->assertFileExists(FCPATH . 'assets/vendor/swagger-ui/swagger-ui.css');
    }
}
