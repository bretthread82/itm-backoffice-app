<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\UserModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Feature tests: real routing, filters and views, signed in as a Shield user.
 * CSRF is switched off here so tests can post forms; CsrfEnforcedTest proves it is on in the app.
 */
abstract class FeatureTestCase extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts signed out: nothing may leak from an earlier test's session.
        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');

        $filters                   = new \Config\Filters();
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
        Factories::injectMock('config', 'Filters', $filters);
    }

    protected function shieldUser(string $email, string $name, bool $admin = false): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $name, 'is_admin' => $admin ? 1 : 0, 'active' => 1]));

        return $users->findById($users->getInsertID());
    }
}
