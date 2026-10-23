<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\I18n\Time;
use Tests\Support\ApiTestCase;

/**
 * @internal
 */
final class ApiAuthTest extends ApiTestCase
{
    private int $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->makeCompany('ACME');
    }

    private function assertError(\CodeIgniter\Test\TestResponse $r, int $status, string $code): void
    {
        $r->assertStatus($status);
        $this->assertStringContainsString('application/json', $r->response()->getHeaderLine('Content-Type'));
        $this->assertSame($code, $this->json($r)['error']['code']);
    }

    public function testNoTokenAndBadTokensAreJson401(): void
    {
        $this->assertError($this->api('api/v1/me', null), 401, 'unauthorized');
        $this->assertError($this->api('api/v1/me', 'not-a-real-token'), 401, 'unauthorized');
        $this->assertSame('Bearer', $this->api('api/v1/me', null)->response()->getHeaderLine('WWW-Authenticate'));
    }

    public function testAValidTokenWorksAndActsAsItsOwner(): void
    {
        $alan = $this->shieldUser('alan@example.test', 'Alan');
        $this->join((int) $alan->id, $this->acme);

        $r = $this->api('api/v1/me', $this->token($alan, name: 'Power BI'));
        $r->assertStatus(200);
        $me = $this->json($r)['data'];

        $this->assertSame((int) $alan->id, $me['id']);
        $this->assertSame('alan@example.test', $me['email']);
        $this->assertFalse($me['is_group_level']);
        $this->assertSame(['ACME'], array_column($me['companies'], 'code'));
        $this->assertSame('Power BI', $me['token']['name']);
        $this->assertSame(['api.read'], $me['token']['scopes']);
    }

    public function testRevokedAndExpiredTokensAreRefused(): void
    {
        $alan = $this->shieldUser('alan@example.test', 'Alan');

        $revoked = $this->token($alan);
        $alan->revokeAccessToken($revoked);
        $this->assertError($this->api('api/v1/me', $revoked), 401, 'unauthorized');

        $expired = $this->token($alan, expires: Time::now()->subDays(1));
        $this->assertError($this->api('api/v1/me', $expired), 401, 'unauthorized');
    }

    public function testATokenWithoutTheReadScopeIsForbidden(): void
    {
        $alan = $this->shieldUser('alan@example.test', 'Alan');

        $this->assertError($this->api('api/v1/me', $this->token($alan, ['other.scope'])), 403, 'insufficient_scope');
    }

    public function testAnInactiveUsersTokenStopsWorking(): void
    {
        $alan  = $this->shieldUser('alan@example.test', 'Alan');
        $token = $this->token($alan);
        $this->api('api/v1/me', $token)->assertStatus(200);

        $this->db->table('users')->where('id', $alan->id)->update(['active' => 0]);

        $this->assertError($this->api('api/v1/me', $token), 403, 'account_disabled');
    }

    public function testABrowserSessionDoesNotAuthenticateTheApi(): void
    {
        $alan = $this->shieldUser('alan@example.test', 'Alan');
        $this->actingAs($alan);

        $r = $this->call('get', 'api/v1/me');

        $this->assertError($r, 401, 'unauthorized');
    }

    public function testPostsAreNotBlockedByCsrfAndAnswerWithJson405(): void
    {
        $alan = $this->shieldUser('alan@example.test', 'Alan');
        $token = $this->token($alan);

        // CSRF protection is on in this test class: a SecurityException here would mean /api is not exempt.
        $r = $this->api('api/v1/invoices', $token, [], 'post');

        $this->assertError($r, 405, 'method_not_allowed');
        $this->assertSame('GET', $r->response()->getHeaderLine('Allow'));
    }

    public function testUnknownEndpointsAreJson404(): void
    {
        $alan = $this->shieldUser('alan@example.test', 'Alan');

        $this->assertError($this->api('api/v1/nothing-here', $this->token($alan)), 404, 'not_found');
    }

    public function testTheApiIsRateLimitedWithJson429(): void
    {
        // The bucket refills continuously, so exercise the filter directly with a tiny limit.
        service('cache')->clean();
        $filter = new \App\Filters\ApiThrottle();
        $request = service('request');

        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $out     = $filter->before($request, ['3']);
            $codes[] = $out === null ? 200 : $out->getStatusCode();
        }

        $this->assertSame([200, 200, 200, 429], array_slice($codes, 0, 4));
        $blocked = $filter->before($request, ['3']);
        $this->assertSame('rate_limited', json_decode((string) $blocked->getBody(), true)['error']['code']);
        $this->assertNotSame('', $blocked->getHeaderLine('Retry-After'));
        service('cache')->clean();
    }

    public function testTheSpecNeedsNoToken(): void
    {
        $r = $this->api('api/v1/openapi.json', null);

        $r->assertStatus(200);
        $spec = $this->json($r);
        $this->assertStringStartsWith('3.1', $spec['openapi']);
        $this->assertStringEndsWith('/api/v1', $spec['servers'][0]['url']);
    }
}
