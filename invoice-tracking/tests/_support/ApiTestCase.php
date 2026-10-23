<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Tests that call /api with a bearer token. Unlike FeatureTestCase this leaves CSRF switched on, so
 * every test here also proves the API is not blocked by it.
 */
abstract class ApiTestCase extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
    }

    protected function shieldUser(string $email, string $name, bool $admin = false, bool $active = true): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $name, 'is_admin' => $admin ? 1 : 0, 'active' => $active ? 1 : 0]));

        return $users->findById($users->getInsertID());
    }

    /**
     * @param list<string> $scopes
     *
     * @return string the raw token, as a client would hold it
     */
    protected function token(User $user, array $scopes = ['api.read'], ?Time $expires = null, string $name = 'test'): string
    {
        return $user->generateAccessToken($name, $scopes, $expires)->raw_token;
    }

    /**
     * A GET to the API as the holder of $token (or with no credentials when null).
     *
     * @param array<string, scalar> $query
     */
    protected function api(string $path, ?string $token, array $query = [], string $method = 'get'): \CodeIgniter\Test\TestResponse
    {
        Services::resetSingle('auth');
        $_SESSION = [];
        Services::resetSingle('session');

        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];
        $uri     = $path . ($query === [] ? '' : '?' . http_build_query($query));

        return $this->withHeaders($headers)->call($method, $uri);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(\CodeIgniter\Test\TestResponse $r): array
    {
        $decoded = json_decode((string) $r->response()->getBody(), true);
        $this->assertIsArray($decoded, 'Response was not JSON: ' . substr((string) $r->response()->getBody(), 0, 200));

        return $decoded;
    }
}
