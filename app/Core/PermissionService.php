<?php

namespace App\Core;

use Config\Backoffice;

/**
 * Resolves what a user may do from their roles. Results are cached per user
 * and must be cleared (forget) whenever roles or role permissions change.
 */
class PermissionService
{
    private const CACHE_TTL = 300;

    /** @var array<int, array{super: bool, keys: list<string>}> */
    private array $memo = [];

    public function can(?int $userId, string $permission): bool
    {
        if ($userId === null) {
            return false;
        }

        // A switched-off module owns no permissions, for anyone.
        if (service('modules')->blocks($permission)) {
            return false;
        }

        $set = $this->load($userId);

        return $set['super'] || in_array($permission, $set['keys'], true);
    }

    /**
     * True if the user holds at least one of the given permissions.
     *
     * @param list<string> $permissions
     */
    public function canAny(?int $userId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($userId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function isSuper(int $userId): bool
    {
        return $this->load($userId)['super'];
    }

    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
        cache()->delete($this->cacheKey($userId));
    }

    /**
     * Clear every cached user, used when a role's permissions change.
     *
     * @param list<int> $userIds
     */
    public function forgetMany(array $userIds): void
    {
        foreach ($userIds as $id) {
            $this->forget((int) $id);
        }
    }

    /**
     * @return array{super: bool, keys: list<string>}
     */
    private function load(int $userId): array
    {
        if (isset($this->memo[$userId])) {
            return $this->memo[$userId];
        }

        $cached = cache($this->cacheKey($userId));

        if (is_array($cached)) {
            return $this->memo[$userId] = $cached;
        }

        $db = db_connect();

        $roleKeys = array_column(
            $db->table('user_roles ur')
                ->select('r.key')
                ->join('roles r', 'r.id = ur.role_id')
                ->where('ur.user_id', $userId)
                ->get()->getResultArray(),
            'key',
        );

        $keys = array_column(
            $db->table('user_roles ur')
                ->distinct()
                ->select('p.key')
                ->join('role_permissions rp', 'rp.role_id = ur.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('ur.user_id', $userId)
                ->get()->getResultArray(),
            'key',
        );

        $set = [
            'super' => in_array(config(Backoffice::class)->superRole, $roleKeys, true),
            'keys'  => $keys,
        ];

        cache()->save($this->cacheKey($userId), $set, self::CACHE_TTL);

        return $this->memo[$userId] = $set;
    }

    private function cacheKey(int $userId): string
    {
        return 'perms_user_' . $userId;
    }
}
