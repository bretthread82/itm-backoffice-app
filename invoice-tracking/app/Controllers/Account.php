<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\AccessScope;
use App\Services\AdminAccess;
use App\Services\AuditLogger;
use App\Services\DelegationResolver;
use App\Services\NotificationService;
use App\Services\WorkflowException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Change password. After an invitation or admin reset the user lands here without being
 * asked for a current password (they arrived through a single-use emailed link).
 */
class Account extends BaseController
{
    public function password(): string
    {
        return view('account/password', [
            'title'  => 'Change password',
            'forced' => auth()->user()->requiresPasswordReset(),
        ]);
    }

    public function notifications(): string
    {
        $uid   = (int) auth()->id();
        $prefs = [];
        foreach (db_connect()->table('notification_prefs')->where('user_id', $uid)->get()->getResultArray() as $p) {
            $prefs[$p['event']] = $p;
        }

        return view('account/notifications', ['title' => 'Notification settings', 'events' => NotificationService::EVENTS, 'prefs' => $prefs]);
    }

    public function saveNotifications(): RedirectResponse
    {
        $uid   = (int) auth()->id();
        $db    = db_connect();
        $email = (array) $this->request->getPost('email');

        foreach (array_keys(NotificationService::EVENTS) as $event) {
            $row = ['email' => isset($email[$event]) ? 1 : 0];
            if ($event === 'digest') {
                $row['daily_digest'] = isset($email[$event]) ? 1 : 0; // one switch: digest on/off
            }

            $existing = $db->table('notification_prefs')->where(['user_id' => $uid, 'event' => $event])->get()->getRowArray();
            $existing === null
                ? $db->table('notification_prefs')->insert($row + ['user_id' => $uid, 'event' => $event, 'daily_digest' => 1])
                : $db->table('notification_prefs')->where('id', $existing['id'])->update($row);
        }

        return redirect()->to('account/notifications')->with('message', 'Your notification settings were saved. In-app alerts (the bell) are always on.');
    }

    // ------------------------------------------------------------------ API tokens

    public const API_SCOPE       = 'api.read';
    public const MAX_API_TOKENS  = 10;
    public const API_TOKEN_DAYS  = [30 => '30 days', 90 => '90 days', 365 => '1 year'];

    public function apiTokens(): string
    {
        return $this->tokensView();
    }

    public function revokeApiToken(int $id): RedirectResponse
    {
        $user  = auth()->user();
        $token = $user->getAccessTokenById($id);   // only ever the signed-in user's own
        if ($token === null) {
            return redirect()->to('account/api-tokens')->with('error', 'That token was not found.');
        }

        $user->revokeAccessTokenBySecret($token->secret);
        (new AuditLogger())->log('api_token.revoked', (int) $user->id, 'user', (int) $user->id, ['name' => $token->name]);

        return redirect()->to('account/api-tokens')->with('message', 'Token revoked. Anything using it stops working immediately.');
    }

    private function tokensView(): string
    {
        return view('account/api_tokens', [
            'title'    => 'API tokens',
            'tokens'   => auth()->user()->accessTokens(),
            'isAdmin'  => (new AdminAccess())->isAdmin((int) auth()->id()),
        ]);
    }

    public function delegations(): string
    {
        $uid = (int) auth()->id();

        return view('account/delegations', [
            'title'       => 'My delegations',
            'delegations' => (new DelegationResolver())->listFor($uid),
            'users'       => db_connect()->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) AS name FROM users WHERE active = 1 AND id <> ? ORDER BY name", [$uid])->getResultArray(),
            'companies'   => (new AccessScope())->companyIdsFor($uid) === null
                ? db_connect()->table('companies')->select('id, code')->orderBy('code')->get()->getResultArray()
                : db_connect()->table('companies')->select('id, code')->whereIn('id', (new AccessScope())->memberCompanyIds($uid) ?: [0])->orderBy('code')->get()->getResultArray(),
            'me'          => $uid,
            'action'      => 'account/delegations',
            'endBase'     => 'account/delegations',
            'admin'       => false,
        ]);
    }

    public function createDelegation(): RedirectResponse
    {
        $uid = (int) auth()->id();

        return $this->makeDelegation($uid, $uid, 'account/delegations');
    }

    public function endDelegation(int $id): RedirectResponse
    {
        try {
            (new DelegationResolver())->revoke((int) auth()->id(), $id);
        } catch (WorkflowException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('account/delegations')->with('message', 'Delegation ended.');
    }

    /**
     * Shared by the user and admin screens.
     */
    protected function makeDelegation(int $actorId, int $delegatorId, string $back): RedirectResponse
    {
        $company = (string) $this->request->getPost('company_id');

        try {
            (new DelegationResolver())->create(
                $actorId,
                $delegatorId,
                (int) $this->request->getPost('delegate_id'),
                $company !== '' ? (int) $company : null,
                (string) $this->request->getPost('starts_at'),
                (string) $this->request->getPost('ends_at'),
                (string) $this->request->getPost('reason'),
            );
        } catch (WorkflowException $e) {
            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with('message', 'Delegation saved. The delegate can now approve for you during that period.');
    }

    public function updatePassword(): RedirectResponse
    {
        $user   = auth()->user();
        $forced = $user->requiresPasswordReset();

        $rules = [
            'password'         => 'required|min_length[' . setting('Auth.minimumPasswordLength') . ']|max_length[255]|strong_password[]',
            'password_confirm' => 'required|matches[password]',
        ];
        if (! $forced) {
            $rules['current_password'] = 'required';
        }

        if (! $this->validate($rules)) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        if (! $forced && ! service('passwords')->verify((string) $this->request->getPost('current_password'), (string) $user->password_hash)) {
            return redirect()->back()->with('errors', ['current_password' => 'The current password is not correct.']);
        }

        $user->password = (string) $this->request->getPost('password');
        model(UserModel::class)->save($user);
        $user->undoForcePasswordReset();

        return redirect()->to('/')->with('message', 'Your password has been changed.');
    }
}
