<?php

namespace App\Core;

use App\Models\StaffProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Backoffice;
use RuntimeException;

/**
 * All user mutations go through here so audit, permission-cache invalidation
 * and the last-Super-Admin guard are applied consistently.
 */
class UserService
{
    private UserModel $users;
    private StaffProfileModel $profiles;

    public function __construct()
    {
        $this->users    = auth()->getProvider();
        $this->profiles = model(StaffProfileModel::class);
    }

    /**
     * @param array<string, mixed> $data  email, first_name, last_name + any profile field
     * @param list<int>            $roleIds
     *
     * @return array{id: int, password: string} one-time password to hand to the user
     */
    public function create(array $data, array $roleIds): array
    {
        $this->assertCanGrant($roleIds, []);

        $password = $this->generatePassword();
        $email    = strtolower(trim($data['email']));

        $user = new User(['email' => $email, 'username' => $this->uniqueUsername($email), 'password' => $password]);

        if (! $this->users->save($user)) {
            throw new RuntimeException(implode(' ', $this->users->errors()));
        }

        $user = $this->users->findById($this->users->getInsertID());
        $user->activate();
        $user->forcePasswordReset();

        $this->profiles->insert($this->profileFields($data) + ['user_id' => $user->id, 'source' => 'local']);

        if ($roleIds === []) {
            $roleIds = $this->defaultRoleIds();
        }

        $this->writeRoles((int) $user->id, $roleIds);

        service('audit')->log('user.created', 'user', $user->id, null, ['email' => $email, 'roles' => $roleIds]);
        service('notifier')->send((int) $user->id, 'account.created', 'Welcome to Back Office', 'Your account has been created. Please set your own password and complete your profile.', 'profile');

        return ['id' => (int) $user->id, 'password' => $password];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->assertCanManage($id);
        $before = $this->profiles->find($id) ?? [];

        $this->profiles->update($id, $this->profileFields($data));

        if (! empty($data['email'])) {
            $user  = $this->users->findById($id);
            $email = strtolower(trim($data['email']));

            if ($user && $user->email !== $email) {
                $user->email = $email;
                $this->users->save($user);
            }
        }

        service('audit')->log('user.updated', 'user', $id, $before, $this->profileFields($data));
    }

    /**
     * @param list<int> $roleIds
     */
    public function setRoles(int $id, array $roleIds): void
    {
        $this->assertCanManage($id);
        $this->assertCanGrant($roleIds, $this->roleIds($id));
        $superId = $this->superRoleId();

        if ($this->isSuper($id) && ! in_array($superId, $roleIds, true) && $this->activeSuperCount() <= 1) {
            throw new RuntimeException('You cannot remove the Super Admin role from the last Super Admin.');
        }

        $before = $this->roleIds($id);
        $this->writeRoles($id, $roleIds);

        service('audit')->log('user.roles_changed', 'user', $id, ['roles' => $before], ['roles' => $roleIds]);

        $sortedOld = $before;
        $sortedNew = array_map('intval', $roleIds);
        sort($sortedOld);
        sort($sortedNew);

        if ($sortedOld !== $sortedNew && $id !== (int) auth()->id()) {
            service('notifier')->send($id, 'account.roles_changed', 'Your roles were updated', 'An administrator changed your access. You may see different menu items.');
        }
    }

    public function setStatus(int $id, string $status): void
    {
        $this->assertCanManage($id);
        if (! in_array($status, ['active', 'inactive', 'suspended'], true)) {
            throw new RuntimeException('Invalid status.');
        }

        if ($status !== 'active') {
            $this->guardNotSelfOrLast($id, 'deactivate');
        }

        $user = $this->users->findById($id);

        if (! $user) {
            throw new RuntimeException('User not found.');
        }

        $before = $this->profiles->find($id)['status'] ?? null;
        $this->profiles->update($id, ['status' => $status]);

        $status === 'active' ? $user->unBan() : $user->ban('Account ' . $status);
        $this->users->save($user);

        service('audit')->log('user.status_changed', 'user', $id, ['status' => $before], ['status' => $status]);
    }

    public function delete(int $id): void
    {
        $this->assertCanManage($id);
        $this->guardNotSelfOrLast($id, 'delete');

        $this->profiles->delete($id);
        $this->users->delete($id);

        service('mfa')->resetAll($id);

        // Free the unique keys (email, employee no, Entra id) so they can be reused or re-synced.
        // The audit log keeps the history.
        db_connect()->table('auth_identities')->where('user_id', $id)->delete();
        db_connect()->table('staff_profiles')->where('user_id', $id)->update(['employee_no' => null, 'entra_object_id' => null]);
        service('permissions')->forget($id);

        service('audit')->log('user.deleted', 'user', $id);
    }

    /**
     * New one-time password for an existing local account.
     */
    public function resetPassword(int $id): string
    {
        $this->assertCanManage($id);
        $user = $this->users->findById($id);

        if (! $user) {
            throw new RuntimeException('User not found.');
        }

        $profile = $this->profiles->find($id);

        if (($profile['source'] ?? 'local') === 'entra') {
            throw new RuntimeException('Passwords for Microsoft Entra accounts are managed in Entra.');
        }

        $password       = $this->generatePassword();
        $user->password = $password;
        $this->users->save($user);
        $user->forcePasswordReset();

        service('notifier')->send($id, 'account.password_reset', 'Your password was reset', 'An administrator reset your password. You will be asked to choose a new one at next sign-in.');

        service('mfa')->revokeDevices($id);
        service('audit')->log('user.password_reset', 'user', $id);

        return $password;
    }

    /**
     * Clears every second factor, recovery code and trusted device for a user who is locked out.
     * Only a Super Admin may do this to a Super Admin.
     */
    public function resetMfa(int $id): void
    {
        $this->assertCanManage($id);

        service('mfa')->resetAll($id);
        service('mfa')->announce($id, 'admin.mfa_reset', 'Your two-step verification was reset', 'An administrator cleared your two-step setup. If it is required for your role you will be asked to set it up again at your next sign-in. If you did not expect this, contact an administrator.');
    }

    /**
     * @return list<int>
     */
    public function roleIds(int $id): array
    {
        return array_map('intval', array_column(
            db_connect()->table('user_roles')->where('user_id', $id)->get()->getResultArray(),
            'role_id',
        ));
    }

    /**
     * @param list<int> $roleIds
     */
    private function writeRoles(int $id, array $roleIds): void
    {
        $db = db_connect();
        $db->transStart();
        $db->table('user_roles')->where('user_id', $id)->delete();

        foreach (array_unique($roleIds) as $roleId) {
            $db->table('user_roles')->insert(['user_id' => $id, 'role_id' => (int) $roleId]);
        }

        $db->transComplete();
        service('permissions')->forget($id);
    }

    /**
     * Only Super Admins may touch Super Admin accounts; anyone else with
     * users.* permissions could otherwise take them over (e.g. password reset).
     */
    private function assertCanManage(int $id): void
    {
        if ($this->isSuper($id) && ! $this->actorIsSuper()) {
            throw new RuntimeException('Only a Super Admin can change a Super Admin account.');
        }
    }

    /**
     * Only Super Admins may grant or revoke the Super Admin role.
     *
     * @param list<int> $newRoleIds
     * @param list<int> $oldRoleIds
     */
    private function assertCanGrant(array $newRoleIds, array $oldRoleIds): void
    {
        $superId = $this->superRoleId();
        $changed = in_array($superId, $newRoleIds, true) !== in_array($superId, $oldRoleIds, true);

        if ($changed && ! $this->actorIsSuper()) {
            throw new RuntimeException('Only a Super Admin can grant or revoke the Super Admin role.');
        }
    }

    private function actorIsSuper(): bool
    {
        $actor = auth()->id();

        return $actor !== null && service('permissions')->isSuper((int) $actor);
    }
    private function guardNotSelfOrLast(int $id, string $verb): void
    {
        if ($id === (int) auth()->id()) {
            throw new RuntimeException("You cannot {$verb} your own account.");
        }

        if ($this->isSuper($id) && $this->activeSuperCount() <= 1) {
            throw new RuntimeException("You cannot {$verb} the last Super Admin.");
        }
    }

    private function isSuper(int $id): bool
    {
        return in_array($this->superRoleId(), $this->roleIds($id), true);
    }

    private function activeSuperCount(): int
    {
        return db_connect()->table('user_roles ur')
            ->join('staff_profiles sp', 'sp.user_id = ur.user_id AND sp.deleted_at IS NULL AND sp.status = \'active\'')
            ->where('ur.role_id', $this->superRoleId())
            ->countAllResults();
    }

    private function superRoleId(): int
    {
        static $id = null;

        return $id ??= (int) (db_connect()->table('roles')->where('key', config(Backoffice::class)->superRole)->get()->getRow()->id ?? 0);
    }

    /**
     * @return list<int>
     */
    private function defaultRoleIds(): array
    {
        $rows = db_connect()->table('roles')->whereIn('key', config(Backoffice::class)->defaultRoles)->get()->getResultArray();

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function profileFields(array $data): array
    {
        $allowed = ['employee_no', 'first_name', 'last_name', 'display_name', 'job_title', 'department_id',
            'manager_id', 'phone', 'mobile', 'location', 'start_date'];
        $out = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value       = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                $out[$field] = $value === '' ? null : $value;
            }
        }

        if (isset($out['first_name'], $out['last_name']) && empty($out['display_name'])) {
            $out['display_name'] = $out['first_name'] . ' ' . $out['last_name'];
        }

        return $out;
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

    private function generatePassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out   = '';

        for ($i = 0; $i < 16; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }
}
