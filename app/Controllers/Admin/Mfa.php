<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Duo;
use Config\Mfa as MfaConfig;

/**
 * Where administrators decide whether MFA is on, which methods are offered,
 * and which roles must use it.
 */
class Mfa extends BaseController
{
    public function index(): string
    {
        $policy = service('mfaPolicy');
        $db     = db_connect();

        $methods = [];

        foreach (MfaConfig::METHODS as $type) {
            $methods[$type] = [
                'on'     => $policy->switchedOn($type),
                'reason' => $policy->unavailableReason($type),
                'users'  => $db->table('user_mfa_methods')->where('type', $type)->countAllResults(),
            ];
        }

        $required = $policy->requiredRoleIds();

        return view('admin/mfa/index', [
            'title'    => 'Multi-factor authentication',
            'enabled'  => $policy->enabled(),
            'methods'  => $methods,
            'roles'    => $db->table('roles')->orderBy('name')->get()->getResultArray(),
            'required' => $required,
            'remember' => $policy->rememberDays(),
            'duoUrl'   => config(Duo::class)->callbackUrl(),
            'notEnrolled' => $required === [] ? 0 : (int) $db->query(
                'SELECT COUNT(DISTINCT ur.user_id) c FROM user_roles ur JOIN staff_profiles sp ON sp.user_id = ur.user_id AND sp.deleted_at IS NULL AND sp.status = \'active\' '
                . 'WHERE ur.role_id IN (' . implode(',', array_map('intval', $required)) . ') AND ur.user_id NOT IN (SELECT user_id FROM user_mfa_methods)',
            )->getRow('c'),
            'events'   => $db->table('audit_log a')->select('a.*, sp.display_name AS actor')->join('staff_profiles sp', 'sp.user_id = a.actor_id', 'left')
                ->groupStart()->like('a.action', 'auth.mfa', 'after')->orLike('a.action', 'admin.mfa', 'after')->groupEnd()
                ->orderBy('a.id', 'DESC')->limit(10)->get()->getResultArray(),
        ]);
    }

    public function save(): RedirectResponse
    {
        $policy = service('mfaPolicy');
        $db     = db_connect();

        $new = [
            'enabled'       => (bool) $this->request->getPost('enabled'),
            'totp'          => (bool) $this->request->getPost('totp'),
            'email'         => (bool) $this->request->getPost('email'),
            'duo'           => (bool) $this->request->getPost('duo'),
            'rememberDays'  => (int) $this->request->getPost('remember_days'),
            'requiredRoles' => array_map('intval', (array) $this->request->getPost('required_roles')),
        ];

        if ($error = $policy->validateChange($new, (int) auth()->id())) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $before = $policy->apply($new);

        service('audit')->log('admin.mfa_settings_changed', 'mfa', null, $before, $new);
        return redirect()->to('admin/mfa')->with('message', 'Two-step verification settings saved.');
    }
}
