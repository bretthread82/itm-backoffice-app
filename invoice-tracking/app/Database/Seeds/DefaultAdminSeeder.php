<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Models\UserModel;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;

/**
 * Creates the one default administrator from .env (never from source code).
 *
 *   admin.email    = someone@example.com
 *   admin.password = a-long-initial-password   (12+ characters)
 *   admin.name     = Optional display name
 *
 * The account is forced to change its password at first login. Re-running is safe: if any
 * administrator already exists, nothing is created.
 */
class DefaultAdminSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('users')->where('is_admin', 1)->countAllResults() > 0) {
            return;
        }

        $email    = (string) env('admin.email', '');
        $password = (string) env('admin.password', '');
        $name     = (string) env('admin.name', 'Administrator');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set a valid admin.email in .env before seeding the default administrator.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Set admin.password in .env (at least 12 characters) before seeding.');
        }

        $users = model(UserModel::class);

        $user = new User([
            'username'     => 'admin',
            'email'        => $email,
            'password'     => $password,
            'display_name' => $name,
            'is_admin'     => 1,
            'active'       => 1,
        ]);
        $users->save($user);

        $user = $users->findById($users->getInsertID());
        $users->addToDefaultGroup($user);
        $user->forcePasswordReset(); // must set a new password at first login
    }
}
