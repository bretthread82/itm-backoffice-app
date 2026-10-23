<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Backoffice;
use RuntimeException;

/**
 * Admin > Modules: switch optional modules on or off. Core modules are listed but locked on.
 */
class Modules extends BaseController
{
    public function index(): string
    {
        $svc  = service('modules');
        $rows = [];

        foreach (config(Backoffice::class)->modules as $key => $module) {
            $optional = $svc->isOptional((string) $key);
            $rows[]   = [
                'key'         => (string) $key,
                'title'       => $module['title'] ?? ucfirst((string) $key),
                'description' => $module['description'] ?? '',
                'optional'    => $optional,
                'enabled'     => $svc->enabled((string) $key),
                'version'     => (string) ($module['version'] ?? ''),
                'perms'       => count($module['permissions']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['optional'], $a['title']] <=> [$a['optional'], $b['title']]);

        return view('admin/modules/index', ['title' => 'Modules', 'subtitle' => 'Switch optional modules on or off. Data is kept when a module is off.', 'modules' => $rows, 'platform' => service('versions')->label()]);
    }

    public function toggle(string $key): RedirectResponse
    {
        $enable = (bool) $this->request->getPost('enabled');

        try {
            service('modules')->setEnabled($key, $enable);
        } catch (RuntimeException $e) {
            return redirect()->to('admin/modules')->with('error', $e->getMessage());
        }

        return redirect()->to('admin/modules')->with('message', 'Module ' . ($enable ? 'enabled.' : 'disabled.'));
    }
}
