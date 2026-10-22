<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Entra\EntraClient;
use App\Core\Entra\EntraSyncService;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Backoffice;
use Config\Entra as EntraConfig;
use Throwable;

/**
 * Entra status, connection test, manual sync and group->role mapping.
 * Credentials live in .env and are only ever shown masked.
 */
class Entra extends BaseController
{
    public function index(): string
    {
        $config = config(EntraConfig::class);
        $db     = db_connect();
        $super  = config(Backoffice::class)->superRole;

        return view('admin/entra/index', [
            'title'    => 'Microsoft Entra',
            'config'   => $config,
            'masked'   => [
                'tenant' => $this->mask($config->tenantId),
                'client' => $this->mask($config->clientId),
                'secret' => $config->clientSecret !== '' && ! str_starts_with($config->clientSecret, 'YOUR-') ? 'Set' : 'Not set',
            ],
            'mappings' => $db->table('entra_group_role_map m')->select('m.*, r.name AS role_name')->join('roles r', 'r.id = m.role_id')->orderBy('r.name')->get()->getResultArray(),
            'roles'    => $db->table('roles')->where('key !=', $super)->orderBy('name')->get()->getResultArray(),
            'runs'     => $db->table('entra_sync_runs')->orderBy('id', 'DESC')->limit(10)->get()->getResultArray(),
        ]);
    }

    public function test(): RedirectResponse
    {
        if (! config(EntraConfig::class)->isConfigured()) {
            return redirect()->back()->with('error', 'Add the tenant id, client id and client secret to .env first.');
        }

        try {
            $org = (new EntraClient())->testConnection();
        } catch (Throwable $e) {
            return redirect()->back()->with('error', 'Connection failed: ' . $e->getMessage());
        }

        return redirect()->back()->with('message', 'Connected to ' . $org['name'] . '.');
    }

    public function sync(): RedirectResponse
    {
        if (! config(EntraConfig::class)->isReady()) {
            return redirect()->back()->with('error', 'Enable and configure Entra in .env first.');
        }

        $lock = fopen(WRITEPATH . 'cache/entra-sync.lock', 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return redirect()->back()->with('error', 'A sync is already running.');
        }

        set_time_limit(300);

        try {
            $stats = (new EntraSyncService())->run();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $summary = sprintf('Sync finished: %d created, %d updated, %d reactivated, %d deactivated.', $stats['created'], $stats['updated'], $stats['reactivated'], $stats['deactivated']);

        return redirect()->back()->with($stats['errors'] === [] ? 'message' : 'warning', $summary . ($stats['errors'] ? ' ' . count($stats['errors']) . ' issue(s): ' . $stats['errors'][0] : ''));
    }

    public function addMapping(): RedirectResponse
    {
        $rules = [
            'entra_group_id' => 'required|regex_match[/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/]',
            'group_name'     => 'permit_empty|max_length[200]',
            'role_id'        => 'required|is_natural_no_zero',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $db   = db_connect();
        $role = $db->table('roles')->where('id', (int) $this->request->getPost('role_id'))->get()->getRowArray();

        // Mapping a directory group to Super Admin would let a group owner in Entra become Super Admin here.
        if (! $role || $role['key'] === config(Backoffice::class)->superRole) {
            return redirect()->back()->withInput()->with('error', 'That role cannot be mapped to an Entra group.');
        }

        $row = [
            'entra_group_id' => strtolower((string) $this->request->getPost('entra_group_id')),
            'group_name'     => trim((string) $this->request->getPost('group_name')) ?: null,
            'role_id'        => (int) $role['id'],
        ];

        if ($db->table('entra_group_role_map')->where(['entra_group_id' => $row['entra_group_id'], 'role_id' => $row['role_id']])->countAllResults()) {
            return redirect()->back()->with('error', 'That mapping already exists.');
        }

        $db->table('entra_group_role_map')->insert($row);
        service('audit')->log('entra.mapping_added', 'entra', $row['entra_group_id'], null, ['role' => $role['key']]);

        return redirect()->back()->with('message', 'Mapping added. It applies on the next sync.');
    }

    public function deleteMapping(): RedirectResponse
    {
        $group = strtolower((string) $this->request->getPost('entra_group_id'));
        $role  = (int) $this->request->getPost('role_id');

        db_connect()->table('entra_group_role_map')->where(['entra_group_id' => $group, 'role_id' => $role])->delete();
        service('audit')->log('entra.mapping_removed', 'entra', $group, ['role_id' => $role]);

        return redirect()->back()->with('message', 'Mapping removed.');
    }

    private function mask(string $value): string
    {
        return preg_match('/^[0-9a-f]{8}-/i', $value) && $value !== '00000000-0000-0000-0000-000000000000'
            ? substr($value, 0, 8) . '-…-' . substr($value, -4)
            : 'Not set';
    }
}
