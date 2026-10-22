<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use Config\Backoffice;

class AppInstall extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'app:install';
    protected $description = 'Seeds roles/permissions and creates the first Super Admin.';
    protected $usage       = 'app:install [--email x --first x --last x --password x]';
    protected $options     = [
        '--email'    => 'Admin email address',
        '--first'    => 'Admin first name',
        '--last'     => 'Admin last name',
        '--password' => 'Admin password (min 12 chars). Prompted if omitted.',
    ];

    public function run(array $params)
    {
        // command() re-initialises CLI option parsing, so read options first.
        $options = [
            'email'    => CLI::getOption('email'),
            'first'    => CLI::getOption('first'),
            'last'     => CLI::getOption('last'),
            'password' => CLI::getOption('password'),
        ];

        CLI::write('Seeding roles and permissions...', 'yellow');
        \Config\Database::seeder()->call('RbacSeeder');

        $db = db_connect();

        if ($db->table('user_roles ur')->join('roles r', 'r.id = ur.role_id')
            ->where('r.key', config(Backoffice::class)->superRole)->countAllResults() > 0) {
            CLI::write('A Super Admin already exists. Nothing more to do.', 'green');

            return EXIT_SUCCESS;
        }

        $email    = $options['email'] ?? CLI::prompt('Admin email', null, 'required|valid_email');
        $first    = $options['first'] ?? CLI::prompt('First name', 'System', 'required');
        $last     = $options['last'] ?? CLI::prompt('Last name', 'Administrator', 'required');
        $password = $options['password'] ?? CLI::prompt('Password (min 12 chars)', null, 'required|min_length[12]');

        if (strlen($password) < 12) {
            CLI::error('Password must be at least 12 characters.');

            return EXIT_ERROR;
        }

        $users = auth()->getProvider();
        $user  = new User(['email' => $email, 'username' => strstr($email, '@', true), 'password' => $password]);

        if (! $users->save($user)) {
            CLI::error('Could not create user: ' . implode('; ', $users->errors()));

            return EXIT_ERROR;
        }

        $user = $users->findById($users->getInsertID());
        $user->activate();
        $user->forcePasswordReset();

        $now = date('Y-m-d H:i:s');
        $db->table('staff_profiles')->insert([
            'user_id' => $user->id, 'first_name' => $first, 'last_name' => $last,
            'display_name' => trim("{$first} {$last}"), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $roleId = $db->table('roles')->where('key', config(Backoffice::class)->superRole)->get()->getRow()->id;
        $db->table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);

        CLI::write("Super Admin created: {$email}", 'green');

        return EXIT_SUCCESS;
    }
}
