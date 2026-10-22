<?php

namespace App\Core\Entra;

use App\Models\StaffProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Backoffice;
use Config\Entra;

/**
 * Maps an Entra user onto a local account. Used by SSO sign-in (just-in-time)
 * and by the directory sync, so both follow identical matching rules:
 *
 *   1. entra_object_id match (the stable key)
 *   2. otherwise email match with an unlinked local account (if allowed)
 *   3. otherwise create (if allowed)
 *
 * An account already linked to a different Entra object is never re-linked.
 */
class EntraUserService
{
    public const BAN_MESSAGE = 'Deactivated by Entra sync';

    private Entra $config;
    private UserModel $users;
    private StaffProfileModel $profiles;

    public function __construct()
    {
        $this->config   = config(Entra::class);
        $this->users    = auth()->getProvider();
        $this->profiles = model(StaffProfileModel::class);
    }

    /**
     * @param array<string, mixed> $a oid, email, first_name, last_name, display_name, job_title,
     *                                department, phone, mobile, location, employee_no, start_date
     *
     * @return array{user_id: int, created: bool, linked: bool}|null null = no matching account and creation not allowed
     */
    public function resolve(array $a, bool $allowCreate): ?array
    {
        $oid   = strtolower((string) $a['oid']);
        $email = strtolower(trim((string) ($a['email'] ?? '')));

        $profile = $this->profiles->where('entra_object_id', $oid)->first();
        $linked  = false;

        if (! $profile && $email !== '' && $this->config->linkExistingByEmail) {
            $userId = $this->userIdByEmail($email);

            if ($userId !== null) {
                $existing = $this->profiles->find($userId);

                if ($existing && empty($existing['entra_object_id'])) {
                    $this->profiles->update($userId, ['entra_object_id' => $oid, 'source' => 'entra']);
                    $profile = $this->profiles->find($userId);
                    $linked  = true;
                    service('audit')->log('entra.account_linked', 'user', $userId, null, ['oid' => $oid], null);
                } else {
                    log_message('warning', 'Entra: {e} already belongs to a different Entra identity; not linking.', ['e' => $email]);

                    return null;
                }
            }
        }

        if (! $profile) {
            if (! $allowCreate || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->userIdByEmail($email) !== null) {
                return null;
            }

            $userId = $this->create($email, $oid, $a);
            $this->refresh($userId, $a);

            return ['user_id' => $userId, 'created' => true, 'linked' => false];
        }

        $this->refresh((int) $profile['user_id'], $a);

        return ['user_id' => (int) $profile['user_id'], 'created' => false, 'linked' => $linked];
    }

    /**
     * Deactivates (bans) an Entra-sourced account. Returns false if it was skipped.
     */
    public function deactivate(int $userId): bool
    {
        $profile = $this->profiles->find($userId);

        if (! $profile || $profile['status'] !== 'active') {
            return false;
        }

        // Never lock out the last way into the system.
        if (service('permissions')->isSuper($userId)) {
            log_message('warning', 'Entra sync skipped deactivating Super Admin user {id}.', ['id' => $userId]);

            return false;
        }

        $this->profiles->update($userId, ['status' => 'inactive']);
        $user = $this->users->findById($userId);

        if ($user) {
            $user->ban(self::BAN_MESSAGE);
            $this->users->save($user);
        }

        service('audit')->log('entra.user_deactivated', 'user', $userId, null, null, null);

        return true;
    }

    /**
     * Re-enables an account that this integration deactivated. Accounts an
     * admin deactivated by hand (different ban message) are left alone.
     */
    public function reactivateIfEntraDisabled(int $userId): bool
    {
        $profile = $this->profiles->find($userId);
        $user    = $this->users->findById($userId);

        if (! $profile || ! $user || $profile['status'] !== 'inactive' || ! $user->isBanned() || $user->status_message !== self::BAN_MESSAGE) {
            return false;
        }

        $this->profiles->update($userId, ['status' => 'active']);
        $user->unBan();
        $this->users->save($user);

        service('audit')->log('entra.user_reactivated', 'user', $userId, null, null, null);

        return true;
    }

    private function create(string $email, string $oid, array $a): int
    {
        $user = new User([
            'email'    => $email,
            'username' => $this->uniqueUsername($email),
            // Entra users sign in with Microsoft; this password is random and never disclosed.
            'password' => bin2hex(random_bytes(32)),
        ]);

        if (! $this->users->save($user)) {
            throw new \RuntimeException(implode(' ', $this->users->errors()));
        }

        $user = $this->users->findById($this->users->getInsertID());
        $user->activate();

        $this->profiles->insert([
            'user_id'         => $user->id,
            'first_name'      => (string) ($a['first_name'] ?? '') !== '' ? (string) $a['first_name'] : (string) strstr($email, '@', true),
            'last_name'       => (string) ($a['last_name'] ?? ''),
            'entra_object_id' => $oid,
            'source'          => 'entra',
        ]);

        $db      = db_connect();
        $roleIds = array_column(
            $db->table('roles')->select('id')->whereIn('key', config(Backoffice::class)->defaultRoles)->get()->getResultArray(),
            'id',
        );

        foreach ($roleIds as $roleId) {
            $db->table('user_roles')->insert(['user_id' => $user->id, 'role_id' => (int) $roleId]);
        }

        service('audit')->log('entra.user_created', 'user', $user->id, null, ['email' => $email], null);
        service('notifier')->send((int) $user->id, 'account.created', 'Welcome to Back Office', 'Your account was created from your Microsoft work account.', 'profile');

        return (int) $user->id;
    }

    /**
     * Directory-owned fields overwrite; user-editable contact fields only fill gaps.
     *
     * @param array<string, mixed> $a
     */
    private function refresh(int $userId, array $a): void
    {
        $current = $this->profiles->find($userId) ?? [];
        $data    = [];

        foreach (['first_name', 'last_name', 'display_name', 'job_title', 'start_date'] as $field) {
            if (isset($a[$field]) && $a[$field] !== '' && $a[$field] !== ($current[$field] ?? null)) {
                $data[$field] = $a[$field];
            }
        }

        foreach (['phone', 'mobile', 'location'] as $field) {
            if (empty($current[$field]) && ! empty($a[$field])) {
                $data[$field] = $a[$field];
            }
        }

        // employee_no is unique: skip rather than fail the whole user on a clash.
        if (! empty($a['employee_no']) && $a['employee_no'] !== ($current['employee_no'] ?? null)
            && ! $this->profiles->where('employee_no', $a['employee_no'])->where('user_id !=', $userId)->first()) {
            $data['employee_no'] = $a['employee_no'];
        }

        if (! empty($a['department'])) {
            $data['department_id'] = $this->departmentId((string) $a['department']);
        }

        if ($data !== []) {
            $this->profiles->update($userId, $data);
        }
    }

    private function departmentId(string $name): int
    {
        $db  = db_connect();
        $row = $db->table('departments')->where('name', $name)->get()->getRowArray();

        if ($row) {
            return (int) $row['id'];
        }

        $db->table('departments')->insert(['name' => mb_substr($name, 0, 120), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        return (int) $db->insertID();
    }

    private function userIdByEmail(string $email): ?int
    {
        $row = db_connect()->table('auth_identities ai')->select('ai.user_id')
            ->join('users u', 'u.id = ai.user_id AND u.deleted_at IS NULL')
            ->where('ai.type', 'email_password')->where('ai.secret', $email)->get()->getRow();

        return $row ? (int) $row->user_id : null;
    }

    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9._-]/', '', strstr($email, '@', true)) ?: 'user';
        $name = $base;
        $i    = 1;

        while ($this->users->withDeleted()->where('username', $name)->first()) {
            $name = $base . ++$i;
        }

        return $name;
    }
}
