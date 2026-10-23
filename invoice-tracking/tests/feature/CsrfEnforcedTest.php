<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class CsrfEnforcedTest extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    public function testStateChangingPostWithoutTokenIsRejected(): void
    {
        $users = model(UserModel::class);
        $users->save(new User(['email' => 'boss@example.test', 'password' => 'a-long-test-password-1', 'display_name' => 'Boss', 'is_admin' => 1, 'active' => 1]));
        $admin = $users->findById($users->getInsertID());

        $this->expectException(SecurityException::class);
        $this->actingAs($admin)->post('admin/companies', ['code' => 'NOPE', 'name' => 'No token']);
    }
}
