<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Account;
use App\Controllers\BaseController;
use App\Services\AdminAccess;
use App\Services\AuditLogger;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\I18n\Time;

/**
 * Personal API tokens. Only administrators can make them (for themselves or for any active user), and they
 * can see every token and revoke one (for example when someone leaves). Secrets are stored hashed, shown once
 * when made, and cannot be shown again.
 */
class ApiTokens extends BaseController
{
    public function index(): string
    {
        return $this->screen(null);
    }

    /** Makes a token for the chosen user. The secret is shown once, on the response to this very request. */
    public function create(): string|RedirectResponse
    {
        $adminId = (int) auth()->id();
        if (! (new AdminAccess())->isAdmin($adminId)) {
            return redirect()->to('/')->with('error', 'Administrator access is required.');
        }

        $name   = trim((string) preg_replace('/\s+/', ' ', (string) $this->request->getPost('name')));
        $days   = (int) $this->request->getPost('days');
        $userId = (int) $this->request->getPost('user_id');
        $owner  = $userId > 0 ? auth()->getProvider()->findById($userId) : null;

        if ($owner === null || ! $owner->active) {
            return redirect()->to('admin/api-tokens')->withInput()->with('error', 'Choose an active user for the token.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return redirect()->to('admin/api-tokens')->withInput()->with('error', 'Give the token a name of up to 100 characters, such as the system that will use it.');
        }
        if (! array_key_exists($days, Account::API_TOKEN_DAYS)) {
            return redirect()->to('admin/api-tokens')->withInput()->with('error', 'Choose how long the token should last.');
        }
        if (count($owner->accessTokens()) >= Account::MAX_API_TOKENS) {
            return redirect()->to('admin/api-tokens')->withInput()->with('error', 'That user already has ' . Account::MAX_API_TOKENS . ' tokens. Revoke one that is no longer used first.');
        }

        $token = $owner->generateAccessToken($name, [Account::API_SCOPE], Time::now()->addDays($days));
        (new AuditLogger())->log('api_token.created', $adminId, 'user', (int) $owner->id, [
            'name' => $name, 'expires' => $token->expires?->toDateTimeString(), 'by_admin' => true, 'for_other' => (int) $owner->id !== $adminId,
        ]);

        return $this->screen($token->raw_token, (string) ($owner->display_name ?? $owner->username ?? ''));
    }

    public function revoke(int $id): RedirectResponse
    {
        $db  = db_connect();
        $row = $db->table('auth_identities')->where(['id' => $id, 'type' => 'access_token'])->get()->getRowArray();
        if ($row === null) {
            return redirect()->to('admin/api-tokens')->with('error', 'That token was not found.');
        }

        $db->table('auth_identities')->where('id', $id)->delete();
        (new AuditLogger())->log('api_token.revoked', (int) auth()->id(), 'user', (int) $row['user_id'], ['name' => $row['name'], 'by_admin' => true]);

        return redirect()->to('admin/api-tokens')->with('message', 'Token revoked.');
    }

    private function screen(?string $newToken, string $newFor = ''): string
    {
        $db   = db_connect();
        $rows = $db->table('auth_identities i')
            ->select("i.id, i.name, i.created_at, i.last_used_at, i.expires, u.id AS user_id, COALESCE(NULLIF(u.display_name, ''), u.username) AS owner, u.active", false)
            ->join('users u', 'u.id = i.user_id')
            ->where('i.type', 'access_token')
            ->orderBy('i.id', 'DESC')
            ->get()->getResultArray();

        return view('admin/api_tokens/index', [
            'title'    => 'API tokens',
            'tokens'   => $rows,
            'users'    => $db->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) AS name FROM users WHERE active = 1 ORDER BY name")->getResultArray(),
            'me'       => (int) auth()->id(),
            'days'     => Account::API_TOKEN_DAYS,
            'newToken' => $newToken,
            'newFor'   => $newFor,
        ]);
    }
}
