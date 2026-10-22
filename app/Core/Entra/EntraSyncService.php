<?php

namespace App\Core\Entra;

use App\Models\StaffProfileModel;
use Config\Backoffice;
use Config\Entra;
use RuntimeException;
use Throwable;

/**
 * One-way directory sync: Entra -> local users, departments, managers and
 * (via the group->role map) roles. Local-only accounts are never touched.
 */
class EntraSyncService
{
    private const USER_FIELDS = 'id,displayName,givenName,surname,mail,userPrincipalName,jobTitle,department,officeLocation,'
        . 'mobilePhone,businessPhones,accountEnabled,employeeId,employeeHireDate,userType';

    private Entra $config;
    private EntraClient $client;
    private EntraUserService $resolver;

    public function __construct(?EntraClient $client = null, ?Entra $config = null)
    {
        $this->config   = $config ?? config(Entra::class);
        $this->client   = $client ?? new EntraClient($this->config);
        $this->resolver = new EntraUserService();
    }

    /**
     * @return array{created: int, updated: int, deactivated: int, reactivated: int, errors: list<string>}
     */
    public function run(): array
    {
        if (! $this->config->isReady()) {
            throw new RuntimeException('Microsoft Entra is not enabled and configured.');
        }

        $db    = db_connect();
        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'reactivated' => 0, 'errors' => []];

        $db->table('entra_sync_runs')->insert(['started_at' => date('Y-m-d H:i:s')]);
        $runId = (int) $db->insertID();

        try {
            $this->sync($stats);
        } catch (Throwable $e) {
            log_message('error', 'Entra sync failed: ' . $e->getMessage());
            $stats['errors'][] = $e->getMessage();
        }

        $db->table('entra_sync_runs')->where('id', $runId)->update([
            'finished_at' => date('Y-m-d H:i:s'),
            'created'     => $stats['created'],
            'updated'     => $stats['updated'],
            'deactivated' => $stats['deactivated'],
            'errors'      => $stats['errors'] === [] ? null : implode("\n", array_slice($stats['errors'], 0, 20)),
        ]);

        service('audit')->log('entra.sync', 'entra', $runId, null, array_diff_key($stats, ['errors' => 1]) + ['error_count' => count($stats['errors'])], null);

        if ($stats['errors'] !== []) {
            service('notifier')->sendToPermission(
                'entra.manage',
                'entra.sync_failed',
                'Microsoft Entra directory sync had errors',
                mb_substr($stats['errors'][0], 0, 300),
                'admin/entra',
            );
        }

        return $stats;
    }

    /**
     * @param array{created: int, updated: int, deactivated: int, reactivated: int, errors: list<string>} $stats
     */
    private function sync(array &$stats): void
    {
        $profiles = model(StaffProfileModel::class);
        $seen     = [];   // oid => local user id (enabled users only)
        $disabled = [];   // oid of accounts Entra reports as disabled
        $managers = [];   // local user id => manager oid
        $fetched  = 0;

        $query = ['$select' => self::USER_FIELDS, '$expand' => 'manager($select=id)', '$top' => 100];

        foreach ($this->client->graphPaged('/users', $query) as $u) {
            $fetched++;

            // Guests and mailbox-less objects (service principals, rooms) are not staff.
            if (($u['userType'] ?? 'Member') !== 'Member') {
                continue;
            }

            $email = strtolower(trim((string) (($u['mail'] ?? null) ?: ($u['userPrincipalName'] ?? ''))));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $enabled = (bool) ($u['accountEnabled'] ?? true);
            $oid     = strtolower((string) $u['id']);

            if (! $enabled) {
                $disabled[$oid] = true;
            }

            $existedBefore = (bool) $profiles->where('entra_object_id', $oid)->first();

            try {
                $result = $this->resolver->resolve([
                    'oid'          => $oid,
                    'email'        => $email,
                    'first_name'   => (string) ($u['givenName'] ?? ''),
                    'last_name'    => (string) ($u['surname'] ?? ''),
                    'display_name' => (string) ($u['displayName'] ?? ''),
                    'job_title'    => (string) ($u['jobTitle'] ?? ''),
                    'department'   => (string) ($u['department'] ?? ''),
                    'phone'        => (string) (($u['businessPhones'][0] ?? '') ?: ''),
                    'mobile'       => (string) ($u['mobilePhone'] ?? ''),
                    'location'     => (string) ($u['officeLocation'] ?? ''),
                    'employee_no'  => (string) ($u['employeeId'] ?? ''),
                    'start_date'   => ! empty($u['employeeHireDate']) ? substr((string) $u['employeeHireDate'], 0, 10) : null,
                ], $enabled && $this->config->syncCreatesUsers);
            } catch (Throwable $e) {
                $stats['errors'][] = "{$email}: " . $e->getMessage();

                continue;
            }

            if ($result === null) {
                continue;
            }

            $result['created'] ? $stats['created']++ : ($existedBefore || $result['linked'] ? $stats['updated']++ : null);

            if ($enabled) {
                $seen[$oid] = $result['user_id'];

                if ($this->resolver->reactivateIfEntraDisabled($result['user_id'])) {
                    $stats['reactivated']++;
                }

                $managerOid = strtolower((string) ($u['manager']['id'] ?? ''));

                if ($managerOid !== '') {
                    $managers[$result['user_id']] = $managerOid;
                }
            }
        }

        // An empty or tiny result almost always means missing Graph permissions, not an empty company.
        if ($fetched === 0) {
            throw new RuntimeException('Microsoft Graph returned no users. Check that User.Read.All is granted with admin consent.');
        }

        $this->applyManagers($managers, $seen);
        $this->applyDeactivations($seen, $disabled, $stats);
        $this->applyGroupRoles($seen, $stats);
    }

    /**
     * @param array<int, string>    $managers
     * @param array<string, int>    $seen
     */
    private function applyManagers(array $managers, array $seen): void
    {
        $profiles = model(StaffProfileModel::class);

        foreach ($managers as $userId => $managerOid) {
            $managerId = $seen[$managerOid] ?? null;

            if ($managerId !== null && $managerId !== $userId) {
                $profiles->update($userId, ['manager_id' => $managerId]);
            }
        }
    }

    /**
     * @param array<string, int>  $seen
     * @param array<string, true> $disabled
     */
    private function applyDeactivations(array $seen, array $disabled, array &$stats): void
    {
        if (! $this->config->syncDeactivates) {
            return;
        }

        $entraRows = model(StaffProfileModel::class)->where('source', 'entra')->where('status', 'active')->findAll();
        $targets   = [];

        foreach ($entraRows as $row) {
            $oid = strtolower((string) $row['entra_object_id']);

            if ($oid !== '' && (isset($disabled[$oid]) || ! isset($seen[$oid]))) {
                $targets[] = (int) $row['user_id'];
            }
        }

        if ($targets === []) {
            return;
        }

        $percent = (int) round(count($targets) / max(1, count($entraRows)) * 100);

        if (count($entraRows) >= 4 && $percent > $this->config->deactivateLimitPercent) {
            $stats['errors'][] = "Skipped deactivating {$percent}% of Entra users (" . count($targets) . ' of ' . count($entraRows)
                . ") because it exceeds the {$this->config->deactivateLimitPercent}% safety limit. Review the directory, then raise the limit if this is intended.";

            return;
        }

        foreach ($targets as $userId) {
            if ($this->resolver->deactivate($userId)) {
                $stats['deactivated']++;
            }
        }
    }

    /**
     * Grants and revokes only roles that appear in the group->role map. Roles
     * assigned by hand and not in the map are preserved. Skipped entirely if any
     * group cannot be read, so partial data never strips access.
     *
     * @param array<string, int> $seen
     */
    private function applyGroupRoles(array $seen, array &$stats): void
    {
        $db       = db_connect();
        $superId  = (int) ($db->table('roles')->where('key', config(Backoffice::class)->superRole)->get()->getRow('id') ?? 0);
        $mappings = $db->table('entra_group_role_map')->get()->getResultArray();
        $mappings = array_values(array_filter($mappings, static fn ($m) => (int) $m['role_id'] !== $superId));

        if ($mappings === []) {
            return;
        }

        $managed   = array_unique(array_map(static fn ($m) => (int) $m['role_id'], $mappings));
        $byGroup   = [];
        $wantRoles = []; // user id => role ids

        foreach ($mappings as $m) {
            $byGroup[strtolower($m['entra_group_id'])][] = (int) $m['role_id'];
        }

        try {
            foreach ($byGroup as $groupId => $roleIds) {
                foreach ($this->client->graphPaged("/groups/{$groupId}/transitiveMembers/microsoft.graph.user", ['$select' => 'id', '$top' => 100]) as $member) {
                    $userId = $seen[strtolower((string) $member['id'])] ?? null;

                    if ($userId !== null) {
                        $wantRoles[$userId] = array_merge($wantRoles[$userId] ?? [], $roleIds);
                    }
                }
            }
        } catch (Throwable $e) {
            $stats['errors'][] = 'Group roles were not updated: ' . $e->getMessage();

            return;
        }

        foreach ($seen as $userId) {
            $current = array_map('intval', array_column($db->table('user_roles')->where('user_id', $userId)->get()->getResultArray(), 'role_id'));
            $target  = array_values(array_unique(array_merge(array_diff($current, $managed), $wantRoles[$userId] ?? [])));

            sort($current);
            sort($target);

            if ($current === $target) {
                continue;
            }

            $db->transStart();
            $db->table('user_roles')->where('user_id', $userId)->delete();

            foreach ($target as $roleId) {
                $db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);
            }

            $db->transComplete();

            service('permissions')->forget($userId);
            service('audit')->log('user.roles_changed', 'user', $userId, ['roles' => $current], ['roles' => $target, 'via' => 'entra'], null);
            service('notifier')->send($userId, 'account.roles_changed', 'Your roles were updated', 'Your access was updated from your Microsoft group membership.');
        }
    }
}
