<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Services\AccessScope;
use App\Services\AdminAccess;
use App\Services\Entra\EntraLogin;
use App\Services\InvitationService;
use App\Services\Memberships;
use App\Services\UserBulkImporter;
use App\Services\UserOffboarding;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

class Users extends BaseController
{
    private const PER_PAGE = 50;

    public function index(): string
    {
        $q      = trim((string) $this->request->getGet('q'));
        $filter = (string) $this->request->getGet('filter');
        $page   = max(1, (int) $this->request->getGet('page'));
        $db     = db_connect();

        $builder = $db->table('users u')
            ->select("u.id, u.display_name, u.username, u.active, u.is_admin, u.is_manager, u.is_workflow, u.email_missing, u.last_active,
                      (SELECT i.secret FROM auth_identities i WHERE i.user_id = u.id AND i.type = 'email_password' LIMIT 1) AS email,
                      (SELECT COUNT(*) FROM user_companies uc WHERE uc.user_id = u.id) AS company_count,
                      (SELECT COUNT(*) FROM group_level_users g WHERE g.user_id = u.id) AS group_level", false);

        if ($q !== '') {
            $builder->groupStart()
                ->like('u.display_name', $q)
                ->orLike('u.username', $q)
                ->orWhere("EXISTS (SELECT 1 FROM auth_identities i2 WHERE i2.user_id = u.id AND i2.type = 'email_password' AND i2.secret LIKE " . $db->escape('%' . $q . '%') . ')', null, false)
                ->groupEnd();
        }
        match ($filter) {
            'disabled'      => $builder->where('u.active', 0),
            'admins'        => $builder->where('u.is_admin', 1),
            'managers'      => $builder->where('u.is_manager', 1),
            'workflow'      => $builder->where('u.is_workflow', 1),
            'email_missing' => $builder->where('u.email_missing', 1),
            'never_signed_in' => $builder->where('u.last_active', null),
            default         => null,
        };

        $total = (clone $builder)->countAllResults(false);
        $users = $builder->orderBy('u.display_name')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('admin/users/index', [
            'title'  => 'Users',
            'users'  => $users,
            'q'      => $q,
            'filter' => $filter,
            'page'   => $page,
            'pages'  => (int) max(1, ceil($total / self::PER_PAGE)),
            'total'  => $total,
            'actorIsAdmin' => (new AdminAccess())->isAdmin((int) auth()->id()),
        ]);
    }

    public function new(): string
    {
        return $this->form(['display_name' => '', 'email' => '', 'is_admin' => 0, 'is_manager' => 0, 'is_workflow' => 0, 'active' => 1], [], false, null);
    }

    public function create(): RedirectResponse
    {
        $rules = [
            'email'        => 'required|valid_email|max_length[254]',
            'display_name' => 'required|max_length[150]',
        ];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $email = strtolower(trim((string) $this->request->getPost('email')));
        if (model(UserModel::class)->findByCredentials(['email' => $email]) !== null) {
            return redirect()->back()->withInput()->with('errors', ['email' => 'A user with that email already exists.']);
        }

        $actor    = (int) auth()->id();
        $isAdmin  = (new AdminAccess())->isAdmin($actor);
        if ($isAdmin && $this->request->getPost('is_manager') && $this->request->getPost('is_workflow')) {
            return redirect()->back()->withInput()->with('error', AdminAccess::MANAGER_OR_WORKFLOW);
        }
        $importer = new UserBulkImporter();

        try {
            $result = $importer->commit([[
                '_line' => '1', 'email' => $email, 'display_name' => trim((string) $this->request->getPost('display_name')),
                'company_codes' => '', 'job_title' => '', 'can_submit' => '', 'can_approve' => '', 'group_level' => $isAdmin ? $this->flag('group_level') : '',
                'is_admin' => $isAdmin ? $this->flag('is_admin') : '', 'active' => 'Y',
            ]], $actor);
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $userId = $result['created_ids'][0];
        if ($isAdmin && $this->request->getPost('is_manager')) {
            (new AdminAccess())->setManager($actor, $userId, true);
        }
        if ($isAdmin && $this->request->getPost('is_workflow')) {
            (new AdminAccess())->setWorkflowManager($actor, $userId, true);
        }
        (new Memberships())->sync($userId, $this->postedMemberships());

        $message = 'User created.';
        if ($this->request->getPost('send_invite')) {
            $message .= (new InvitationService())->send($userId, $actor) ? ' An invitation was emailed.' : ' The invitation email could not be sent; use Resend invitation.';
        }

        return redirect()->to('admin/users/' . $userId . '/edit')->with('message', $message);
    }

    public function edit(int $id): string
    {
        $row = $this->userRow($id);

        return $this->form($row, (new AccessScope())->memberCompanyIds($id), (bool) $row['group_level'], $id);
    }

    public function update(int $id): RedirectResponse
    {
        $row = $this->userRow($id);

        if (! $this->validate(['display_name' => 'required|max_length[150]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $actor  = (int) auth()->id();
        $access = new AdminAccess();

        try {
            $access->requireCanAdminister($actor, $id);
            // Refuse the impossible combination before anything is saved.
            if ($access->isAdmin($actor) && $this->request->getPost('is_manager') && $this->request->getPost('is_workflow')) {
                throw new RuntimeException(AdminAccess::MANAGER_OR_WORKFLOW);
            }
            $email = trim((string) $this->request->getPost('email'));
            if ($email !== '') {
                $access->setEmail($actor, $id, $email);
            }
            db_connect()->table('users')->where('id', $id)->update(['display_name' => trim((string) $this->request->getPost('display_name'))]);
            (new Memberships())->sync($id, $this->postedMemberships());

            $wantActive = (bool) $this->request->getPost('active');

            // Only administrators see (and may change) the admin, manager, workflow-manager and group-level switches.
            if ($access->isAdmin($actor)) {
                $wantAdmin   = (bool) $this->request->getPost('is_admin');
                $wantManager = (bool) $this->request->getPost('is_manager');
                $wantWorkflow = (bool) $this->request->getPost('is_workflow');
                $wantGroup   = (bool) $this->request->getPost('group_level');

                if ($wantAdmin !== (bool) $row['is_admin']) {
                    $access->setAdmin($actor, $id, $wantAdmin);
                }
                // Switch one off before the other goes on, so changing a user from one level to the other works in one save.
                $workflowChanged = $wantWorkflow !== (bool) ($row['is_workflow'] ?? 0);
                if ($workflowChanged && ! $wantWorkflow) {
                    $access->setWorkflowManager($actor, $id, false);
                }
                if ($wantManager !== (bool) $row['is_manager']) {
                    $access->setManager($actor, $id, $wantManager);
                }
                if ($workflowChanged && $wantWorkflow) {
                    $access->setWorkflowManager($actor, $id, true);
                }
                if ($wantGroup !== (bool) $row['group_level']) {
                    $wantGroup ? $access->grantGroupLevel($actor, $id) : $access->revokeGroupLevel($actor, $id);
                }
            }
            if ($wantActive !== (bool) $row['active']) {
                $access->setActive($actor, $id, $wantActive);
            }
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/users/' . $id . '/edit')->with('message', 'User saved.');
    }

    public function invite(int $id): RedirectResponse
    {
        $this->userRow($id);
        $sent = (new InvitationService())->send($id, (int) auth()->id());

        return redirect()->back()->with($sent ? 'message' : 'error', $sent ? 'Invitation emailed.' : 'The invitation could not be sent. Check the user has an email address and that email delivery is configured.');
    }

    /**
     * Admin sets a temporary password; the user must change it at next sign-in.
     */
    public function resetPassword(int $id): RedirectResponse
    {
        $this->userRow($id);

        $rules = [
            'new_password'         => 'required|min_length[' . setting('Auth.minimumPasswordLength') . ']|max_length[255]|strong_password[]',
            'new_password_confirm' => 'required|matches[new_password]',
        ];
        if (! $this->validate($rules)) {
            return redirect()->to('admin/users/' . $id . '/edit')->with('errors', $this->validator->getErrors());
        }

        try {
            (new AdminAccess())->setTemporaryPassword((int) auth()->id(), $id, (string) $this->request->getPost('new_password'));
        } catch (RuntimeException $e) {
            return redirect()->to('admin/users/' . $id . '/edit')->with('error', $e->getMessage());
        }

        return redirect()->to('admin/users/' . $id . '/edit')->with('message', 'Temporary password set. The user must choose a new one when they next sign in.');
    }

    /**
     * Moves a leaving user's approvals, routes and unfinished invoices to a replacement, optionally disabling them.
     */
    public function transferWork(int $id): RedirectResponse
    {
        $this->userRow($id);
        $to = (int) $this->request->getPost('replacement_id');
        if ($to <= 0) {
            return redirect()->to('admin/users/' . $id . '/edit')->with('error', 'Choose who takes over.');
        }

        $disable = (bool) $this->request->getPost('disable');

        try {
            $r = (new UserOffboarding())->transfer((int) auth()->id(), $id, $to, $disable);
        } catch (RuntimeException $e) {
            return redirect()->to('admin/users/' . $id . '/edit')->with('error', $e->getMessage());
        }

        $msg = "Transferred: {$r['steps']} approval step(s), {$r['route_steps']} route step(s), {$r['submitted']} unfinished invoice(s); {$r['delegations']} delegation(s) ended.";

        return redirect()->to('admin/users/' . $id . '/edit')->with('message', $msg . ($disable ? ' The account was disabled.' : ''));
    }

    /**
     * Forget the Microsoft account linked to this user (for example after they change their Microsoft
     * identity). The next Microsoft sign-in links again by email.
     */
    public function unlinkMicrosoft(int $id): RedirectResponse
    {
        $this->userRow($id);

        try {
            (new AdminAccess())->requireCanAdminister((int) auth()->id(), $id);
        } catch (RuntimeException $e) {
            return redirect()->to('admin/users/' . $id . '/edit')->with('error', $e->getMessage());
        }
        (new EntraLogin())->unlink($id);
        (new \App\Services\AuditLogger())->log('user.microsoft_unlinked', (int) auth()->id(), 'user', $id);

        return redirect()->to('admin/users/' . $id . '/edit')->with('message', 'The Microsoft account was unlinked.');
    }

    /**
     * Invites every active user with an email who has never signed in.
     */
    public function invitePending(): RedirectResponse
    {
        $ids = db_connect()->table('users')->select('id')
            ->where('active', 1)->where('email_missing', 0)->where('last_active', null)
            ->get()->getResultArray();

        $sent = $failed = 0;
        $svc  = new InvitationService();
        foreach ($ids as $row) {
            $svc->send((int) $row['id'], (int) auth()->id()) ? $sent++ : $failed++;
        }

        return redirect()->to('admin/users')->with($failed ? 'warning' : 'message', "Invitations sent: {$sent}. Failed or skipped: {$failed}.");
    }

    /**
     * @param array<string, mixed> $user
     * @param list<int>            $memberIds
     */
    private function form(array $user, array $memberIds, bool $groupLevel, ?int $id): string
    {
        $db          = db_connect();
        $memberships = [];
        if ($id !== null) {
            foreach ($db->table('user_companies')->where('user_id', $id)->get()->getResultArray() as $m) {
                $memberships[(int) $m['company_id']] = $m;
            }
        }

        return view('admin/users/form', [
            'title'       => $id === null ? 'New user' : 'Edit user',
            'user'        => $user,
            'id'          => $id,
            'groupLevel'  => $groupLevel,
            'companies'   => $db->table('companies')->select('id, code, name, active')->orderBy('code')->get()->getResultArray(),
            'memberships' => $memberships,
            'actorIsAdmin' => (new AdminAccess())->isAdmin((int) auth()->id()),
            'isSelf'      => $id !== null && $id === (int) auth()->id(),
            'offboard'    => $id === null ? null : (new UserOffboarding())->preview($id),
            'replacements' => $id === null ? [] : $db->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) AS name FROM users WHERE active = 1 AND id <> ? ORDER BY name", [$id])->getResultArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(int $id): array
    {
        $row = db_connect()->table('users u')
            ->select("u.*, (SELECT i.secret FROM auth_identities i WHERE i.user_id = u.id AND i.type = 'email_password' LIMIT 1) AS email,
                      (SELECT COUNT(*) FROM group_level_users g WHERE g.user_id = u.id) AS group_level", false)
            ->where('u.id', $id)->get()->getRowArray();

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $row;
    }

    /**
     * @return array<int, array{can_submit: bool, job_title: string|null}>
     */
    private function postedMemberships(): array
    {
        $member = (array) $this->request->getPost('member');
        $submit  = (array) $this->request->getPost('submit');
        $approve = (array) $this->request->getPost('approve');
        $title   = (array) $this->request->getPost('title');

        $valid = array_column(db_connect()->table('companies')->select('id')->get()->getResultArray(), 'id');
        $out   = [];
        // Ticking "Can submit" or "Can approve" implies membership, so the choice is never silently dropped.
        foreach (array_unique([...array_keys($member), ...array_keys($submit), ...array_keys($approve)]) as $companyId) {
            if (! in_array((string) $companyId, array_map('strval', $valid), true)) {
                continue;
            }
            $out[(int) $companyId] = [
                'can_submit'  => isset($submit[$companyId]),
                'can_approve' => isset($approve[$companyId]),
                'job_title'   => trim((string) ($title[$companyId] ?? '')) ?: null,
            ];
        }

        return $out;
    }

    private function flag(string $name): string
    {
        return $this->request->getPost($name) ? 'Y' : 'N';
    }
}
