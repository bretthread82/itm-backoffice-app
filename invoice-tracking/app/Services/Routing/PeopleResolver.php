<?php

declare(strict_types=1);

namespace App\Services\Routing;

use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;

/**
 * Maps the people named on routes to user accounts.
 *
 * Match order: email, then (for people with no email in the export) the normalised name.
 * A person with no match becomes a placeholder user: inactive, "email missing", unable to sign in.
 * When an email later arrives for a placeholder, the email is attached and the user activated
 * (they still cannot sign in until they accept an invitation and set a password).
 */
final class PeopleResolver
{
    public const MATCHED_EMAIL   = 'matched_email';
    public const MATCHED_NAME    = 'matched_name';
    public const FILL_EMAIL      = 'fill_email';      // placeholder found; export now has its email
    public const NEW_WITH_EMAIL  = 'new_with_email';
    public const NEW_PLACEHOLDER = 'new_placeholder';
    public const AMBIGUOUS       = 'ambiguous';

    /** @var array<string, int> */
    private array $byEmail = [];

    /** @var array<string, list<array{id: int, email_missing: bool}>> */
    private array $byName = [];

    public function __construct(private readonly BaseConnection $db)
    {
        $this->load();
    }

    /**
     * Read-only: what would happen for this person.
     *
     * @return array{status: string, user_id: int|null}
     */
    public function classify(string $name, string $email): array
    {
        $email = strtolower(trim($email));
        $key   = RoutingNormalizer::personKey($name);

        if ($email !== '' && isset($this->byEmail[$email])) {
            return ['status' => self::MATCHED_EMAIL, 'user_id' => $this->byEmail[$email]];
        }

        $candidates = $this->byName[$key] ?? [];

        if ($email !== '') {
            $placeholders = array_values(array_filter($candidates, static fn (array $c): bool => $c['email_missing']));
            if (count($placeholders) === 1) {
                return ['status' => self::FILL_EMAIL, 'user_id' => $placeholders[0]['id']];
            }

            return ['status' => self::NEW_WITH_EMAIL, 'user_id' => null];
        }

        return match (count($candidates)) {
            0       => ['status' => self::NEW_PLACEHOLDER, 'user_id' => null],
            1       => ['status' => self::MATCHED_NAME, 'user_id' => $candidates[0]['id']],
            default => ['status' => self::AMBIGUOUS, 'user_id' => null],
        };
    }

    /**
     * Writes whatever is needed and returns the user id (null when ambiguous).
     *
     * @return array{user_id: int|null, status: string}
     */
    public function resolve(string $name, string $email): array
    {
        $class = $this->classify($name, $email);
        $email = strtolower(trim($email));

        switch ($class['status']) {
            case self::MATCHED_EMAIL:
            case self::MATCHED_NAME:
            case self::AMBIGUOUS:
                return $class;

            case self::FILL_EMAIL:
                $this->attachEmail((int) $class['user_id'], $email);

                return $class;

            case self::NEW_WITH_EMAIL:
                $id = $this->createWithEmail($name, $email);

                return ['status' => self::NEW_WITH_EMAIL, 'user_id' => $id];

            default:
                $id = $this->createPlaceholder($name);

                return ['status' => self::NEW_PLACEHOLDER, 'user_id' => $id];
        }
    }

    private function load(): void
    {
        $rows = $this->db->table('users u')
            ->select("u.id, u.display_name, u.username, u.email_missing,
                      (SELECT i.secret FROM auth_identities i WHERE i.user_id = u.id AND i.type = 'email_password' LIMIT 1) AS email", false)
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (! empty($r['email'])) {
                $this->byEmail[strtolower((string) $r['email'])] = $id;
            }
            $name = (string) ($r['display_name'] ?: $r['username']);
            if ($name !== '') {
                $this->byName[RoutingNormalizer::personKey($name)][] = ['id' => $id, 'email_missing' => (bool) $r['email_missing']];
            }
        }
    }

    private function createWithEmail(string $name, string $email): int
    {
        $users = model(UserModel::class);
        $users->save(new User([
            'email'         => $email,
            'password'      => bin2hex(random_bytes(24)), // unusable until the user accepts an invitation
            'display_name'  => $name,
            'active'        => 1,
            'is_admin'      => 0,
        ]));
        $id = (int) $users->getInsertID();
        $users->addToDefaultGroup($users->findById($id));

        $this->remember($id, $name, $email, false);

        return $id;
    }

    private function createPlaceholder(string $name): int
    {
        $this->db->table('users')->insert([
            'display_name'  => $name,
            'active'        => 0,
            'is_admin'      => 0,
            'email_missing' => 1,
            'auth_source'   => 'local',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();

        $this->db->table('auth_groups_users')->insert([
            'user_id' => $id, 'group' => setting('AuthGroups.defaultGroup') ?: 'user', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->remember($id, $name, '', true);

        return $id;
    }

    private function attachEmail(int $userId, string $email): void
    {
        $users = model(UserModel::class);
        $user  = $users->findById($userId);
        $user->createEmailIdentity(['email' => $email, 'password' => bin2hex(random_bytes(24))]);

        $this->db->table('users')->where('id', $userId)->update(['email_missing' => 0, 'active' => 1]);

        $this->byEmail[$email] = $userId;
        foreach ($this->byName as &$list) {
            foreach ($list as &$c) {
                if ($c['id'] === $userId) {
                    $c['email_missing'] = false;
                }
            }
        }
    }

    private function remember(int $id, string $name, string $email, bool $missing): void
    {
        if ($email !== '') {
            $this->byEmail[$email] = $id;
        }
        $this->byName[RoutingNormalizer::personKey($name)][] = ['id' => $id, 'email_missing' => $missing];
    }
}
