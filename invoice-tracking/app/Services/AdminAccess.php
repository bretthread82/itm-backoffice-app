<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;

/**
 * Privileged user changes, all audited. Granting admin, manager, workflow-manager or group-level access needs an
 * administrator. Disabling accounts and resetting passwords also work for managers, but a manager
 * can never act on an administrator's account.
 */
class AdminAccess
{
    public function __construct(
        private ?ConnectionInterface $db = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->db ??= db_connect();
        $this->audit ??= new AuditLogger($this->db);
    }

    public function isAdmin(int $userId): bool
    {
        return $this->db->table('users')->where('id', $userId)->where('is_admin', 1)->where('active', 1)->countAllResults() > 0;
    }

    public function isManager(int $userId): bool
    {
        return $this->db->table('users')->where('id', $userId)->where('is_manager', 1)->where('active', 1)->countAllResults() > 0;
    }

    /** Workflow Manager: the narrow level that maintains only invoice types, suppliers, routes and route variants. */
    public function isWorkflowManager(int $userId): bool
    {
        return $this->db->table('users')->where('id', $userId)->where('is_workflow', 1)->where('active', 1)->countAllResults() > 0;
    }

    /** Administrators and managers: workflow and day-to-day administration. */
    public function canManage(int $userId): bool
    {
        return $this->isAdmin($userId) || $this->isManager($userId);
    }

    /** May open the workflow settings screens: administrators, managers and workflow managers. Not a substitute for canManage(). */
    public function canManageWorkflow(int $userId): bool
    {
        return $this->canManage($userId) || $this->isWorkflowManager($userId);
    }

    /**
     * Managers may administer ordinary users and other managers, never administrators.
     *
     * @throws RuntimeException
     */
    public function requireCanAdminister(int $actorId, int $targetUserId): void
    {
        $this->requireManager($actorId);

        if ($this->isAdmin($targetUserId) && ! $this->isAdmin($actorId)) {
            throw new RuntimeException('Only an administrator can change an administrator.');
        }
    }

    public function grantGroupLevel(int $actorId, int $targetUserId): void
    {
        $this->requireAdmin($actorId);
        $this->requireUser($targetUserId);

        if ($this->db->table('group_level_users')->where('user_id', $targetUserId)->countAllResults() > 0) {
            return;
        }

        $this->db->table('group_level_users')->insert([
            'user_id'    => $targetUserId,
            'granted_by' => $actorId,
            'granted_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit->log('group_level.granted', $actorId, 'user', $targetUserId);
    }

    public function revokeGroupLevel(int $actorId, int $targetUserId): void
    {
        $this->requireAdmin($actorId);

        $this->db->table('group_level_users')->where('user_id', $targetUserId)->delete();
        $this->audit->log('group_level.revoked', $actorId, 'user', $targetUserId);
    }

    public function setAdmin(int $actorId, int $targetUserId, bool $isAdmin): void
    {
        $this->requireAdmin($actorId);
        $this->requireUser($targetUserId);

        if (! $isAdmin && $this->wouldRemoveLastAdmin($targetUserId)) {
            throw new RuntimeException('The last active administrator cannot be demoted.');
        }

        $this->db->table('users')->where('id', $targetUserId)->update(['is_admin' => $isAdmin ? 1 : 0]);
        $this->audit->log($isAdmin ? 'admin.granted' : 'admin.revoked', $actorId, 'user', $targetUserId);
    }

    public const MANAGER_OR_WORKFLOW = 'A user cannot be both a Manager and a Workflow Manager. Turn one off before turning the other on.';

    public function setManager(int $actorId, int $targetUserId, bool $isManager): void
    {
        $this->requireAdmin($actorId);
        $this->requireUser($targetUserId);

        if ($isManager && $this->db->table('users')->where('id', $targetUserId)->where('is_workflow', 1)->countAllResults() > 0) {
            throw new RuntimeException(self::MANAGER_OR_WORKFLOW);
        }

        $this->db->table('users')->where('id', $targetUserId)->update(['is_manager' => $isManager ? 1 : 0]);
        $this->audit->log($isManager ? 'manager.granted' : 'manager.revoked', $actorId, 'user', $targetUserId);
    }

    public function setWorkflowManager(int $actorId, int $targetUserId, bool $isWorkflow): void
    {
        $this->requireAdmin($actorId);
        $this->requireUser($targetUserId);

        // Workflow Manager is the narrower level; a Manager already has everything it opens, so the two never combine.
        if ($isWorkflow && $this->db->table('users')->where('id', $targetUserId)->where('is_manager', 1)->countAllResults() > 0) {
            throw new RuntimeException(self::MANAGER_OR_WORKFLOW);
        }

        $this->db->table('users')->where('id', $targetUserId)->update(['is_workflow' => $isWorkflow ? 1 : 0]);
        $this->audit->log($isWorkflow ? 'workflow_manager.granted' : 'workflow_manager.revoked', $actorId, 'user', $targetUserId);
    }

    /**
     * Sets or changes the sign-in email. A placeholder created by a routing import (no email yet) is
     * activated once it has one.
     */
    public function setEmail(int $actorId, int $targetUserId, string $email): void
    {
        $this->requireUser($targetUserId);
        $this->requireCanAdminister($actorId, $targetUserId);

        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new RuntimeException('Enter a valid email address.');
        }

        $taken = $this->db->table('auth_identities')->where('type', 'email_password')->where('secret', $email)
            ->where('user_id !=', $targetUserId)->countAllResults();
        if ($taken > 0) {
            throw new RuntimeException('Another user already has that email address.');
        }

        $identity = $this->db->table('auth_identities')->where(['user_id' => $targetUserId, 'type' => 'email_password'])->get()->getRowArray();
        if ($identity !== null) {
            if (strtolower((string) $identity['secret']) === $email) {
                return;
            }
            $this->db->table('auth_identities')->where('id', $identity['id'])->update(['secret' => $email]);
        } else {
            $user = model(UserModel::class)->findById($targetUserId);
            if (! $user instanceof User) {
                throw new RuntimeException('User not found.');
            }
            $user->createEmailIdentity(['email' => $email, 'password' => bin2hex(random_bytes(24))]); // unusable until invited
        }

        $wasMissing = (int) ($this->db->table('users')->select('email_missing')->where('id', $targetUserId)->get()->getRowArray()['email_missing'] ?? 0) === 1;
        $update     = ['email_missing' => 0];
        if ($wasMissing) {
            $update['active'] = 1;
        }
        $this->db->table('users')->where('id', $targetUserId)->update($update);
        $this->audit->log('user.email_set', $actorId, 'user', $targetUserId);
    }

    public function setActive(int $actorId, int $targetUserId, bool $active): void
    {
        $this->requireUser($targetUserId);
        $this->requireCanAdminister($actorId, $targetUserId);

        if (! $active && $this->wouldRemoveLastAdmin($targetUserId)) {
            throw new RuntimeException('The last active administrator cannot be disabled.');
        }

        $this->db->table('users')->where('id', $targetUserId)->update(['active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'user.enabled' : 'user.disabled', $actorId, 'user', $targetUserId);
    }

    /**
     * Sets a temporary password for a local account and forces the user to change it at next
     * sign-in. The password is never logged.
     */
    public function setTemporaryPassword(int $actorId, int $targetUserId, string $password): void
    {
        $this->requireUser($targetUserId);
        $this->requireCanAdminister($actorId, $targetUserId);

        $user = model(UserModel::class)->findById($targetUserId);
        if (! $user instanceof User || $user->email === null || $user->email === '') {
            throw new RuntimeException('This user has no local account to reset.');
        }

        $user->password = $password;
        model(UserModel::class)->save($user);
        $user->forcePasswordReset();

        $this->audit->log('user.password_reset', $actorId, 'user', $targetUserId);
    }

    private function wouldRemoveLastAdmin(int $targetUserId): bool
    {
        if (! $this->isAdmin($targetUserId)) {
            return false;
        }

        $activeAdmins = $this->db->table('users')->where('is_admin', 1)->where('active', 1)->countAllResults();

        return $activeAdmins <= 1;
    }

    private function requireAdmin(int $actorId): void
    {
        if (! $this->isAdmin($actorId)) {
            throw new RuntimeException('Administrator access is required.');
        }
    }

    private function requireManager(int $actorId): void
    {
        if (! $this->canManage($actorId)) {
            throw new RuntimeException('Manager access is required.');
        }
    }

    private function requireUser(int $userId): void
    {
        if ($this->db->table('users')->where('id', $userId)->countAllResults() === 0) {
            throw new RuntimeException('User not found.');
        }
    }
}
