<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\RouteVariantModel;
use App\Services\AuditLogger;
use App\Services\DefaultTypeAssigner;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * Approval routes and the route builder. A route is the ordered chain for one
 * (company, invoice type, variant). Invoices snapshot the route when submitted, so edits here
 * never change invoices already in flight.
 */
class Routes extends BaseController
{
    private const STEP_TYPES     = ['submit', 'approve', 'process', 'pay', 'notify'];
    private const ASSIGNEE_KINDS = ['user', 'external', 'team'];
    private const TRIGGERS       = ['none', 'manual_flag', 'amount_threshold'];
    private const PER_PAGE       = 100;

    public function index(): string
    {
        $db      = db_connect();
        $company = (int) $this->request->getGet('company');
        $status  = (string) $this->request->getGet('status');
        $variant = (string) $this->request->getGet('variant');
        $q       = trim((string) $this->request->getGet('q'));
        $page    = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('routes r')
            ->select('r.id, r.variant, r.variant_trigger, r.active, r.version, c.code, c.name AS company_name, t.name AS type_name, t.active AS type_active, t.high_priority,
                      (SELECT COUNT(*) FROM route_steps s WHERE s.route_id = r.id) AS step_count')
            ->join('companies c', 'c.id = r.company_id')
            ->join('invoice_types t', 't.id = r.invoice_type_id');

        if ($company > 0) {
            $builder->where('r.company_id', $company);
        }
        if ($status === 'active') {
            $builder->where('r.active', 1);
        } elseif ($status === 'inactive') {
            $builder->where('r.active', 0);
        }
        $variants = new RouteVariantModel();
        if (array_key_exists($variant, $variants->labels())) {
            $builder->where('r.variant', $variant);
        }
        if ($q !== '') {
            $builder->like('t.name', $q);
        }

        $total  = (clone $builder)->countAllResults(false);
        $routes = $builder->orderBy('c.code')->orderBy('t.name')->orderBy('r.variant')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('admin/routes/index', [
            'title'     => 'Routes',
            'routes'    => $routes,
            'companies' => $db->table('companies')->select('id, code')->orderBy('code')->get()->getResultArray(),
            'f'         => ['company' => $company, 'status' => $status, 'variant' => $variant, 'q' => $q],
            'variants'  => $variants->labels(),
            'page'      => $page,
            'pages'     => (int) max(1, ceil($total / self::PER_PAGE)),
            'total'     => $total,
        ]);
    }

    public function new(): string
    {
        return view('admin/routes/new', [
            'title'     => 'New route',
            'companies' => db_connect()->table('companies')->select('id, code, name')->where('active', 1)->orderBy('code')->get()->getResultArray(),
            'variants'  => (new RouteVariantModel())->active(),
            'types'     => db_connect()->table('default_invoice_types')->select('id, name')->where('active', 1)->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $allowed = implode(',', array_column((new RouteVariantModel())->active(), 'variant_key'));
        $db      = db_connect();
        $choice  = (string) $this->request->getPost('type_choice');
        $default = null;
        if (ctype_digit($choice)) {
            $default = $db->table('default_invoice_types')->where('id', (int) $choice)->where('active', 1)->get()->getRowArray();
            if ($default === null) {
                return redirect()->back()->withInput()->with('error', 'Choose an invoice type from the list, or Other.');
            }
        }

        $rules = ['company_id' => 'required|is_natural_no_zero', 'variant' => 'required|in_list[' . $allowed . ']'];
        if ($default === null) {
            $rules['type_name'] = 'required|max_length[200]';
        }
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $companyId = (int) $this->request->getPost('company_id');
        $name      = $default !== null ? (string) $default['name'] : trim((string) preg_replace('/\s+/', ' ', (string) $this->request->getPost('type_name')));
        $variant   = (string) $this->request->getPost('variant');

        if ($db->table('companies')->where('id', $companyId)->countAllResults() === 0) {
            return redirect()->back()->withInput()->with('error', 'Choose a company.');
        }

        if ($default !== null) {
            (new DefaultTypeAssigner($db))->assign((int) $default['id'], $name, $companyId);
        }

        $type = $db->table('invoice_types')->where('company_id', $companyId)->where('LOWER(name)', strtolower($name))->get()->getRowArray();
        if ($type === null) {
            $db->table('invoice_types')->insert(['company_id' => $companyId, 'name' => $name, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
            $typeId = (int) $db->insertID();
        } else {
            $typeId = (int) $type['id'];
        }

        if ($db->table('routes')->where(['company_id' => $companyId, 'invoice_type_id' => $typeId, 'variant' => $variant])->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'A route for that company, invoice type and variant already exists.');
        }

        $db->table('routes')->insert([
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'variant' => $variant,
            'variant_trigger' => 'none', 'version' => 1, 'active' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        (new AuditLogger())->log('route.created', (int) auth()->id(), 'route', $id);

        return redirect()->to('admin/routes/' . $id . '/edit')->with('message', 'Route created. Add its steps, then switch it on.');
    }

    public function edit(int $id): string
    {
        helper('route');

        $db    = db_connect();
        $route = $this->route($id);

        $steps = $db->table('route_steps s')
            ->select('s.*, j.name AS job_title')
            ->join('job_titles j', 'j.id = s.job_title_id', 'left')
            ->where('s.route_id', $id)->orderBy('s.step_order')->get()->getResultArray();

        $users = $db->query(
            "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, u.active, u.email_missing,
                COALESCE(
                    (SELECT NULLIF(uc.job_title, '') FROM user_companies uc WHERE uc.user_id = u.id AND uc.company_id = ?),
                    (SELECT MIN(NULLIF(uc.job_title, '')) FROM user_companies uc WHERE uc.user_id = u.id)
                ) AS job_title
             FROM users u ORDER BY name",
            [(int) $route['company_id']],
        )->getResultArray();

        return view('admin/routes/edit', [
            'title'  => $route['type_name'] . ' · ' . $route['code'],
            'route'  => $route,
            'variantName' => (new RouteVariantModel())->name((string) $route['variant']),
            'steps'  => $steps,
            'users'  => $users,
            'teams'  => $db->table('teams')->select('id, name, active')->orderBy('active', 'DESC')->orderBy('name')->get()->getResultArray(),
            'titles' => array_column($db->table('job_titles')->select('name')->orderBy('name')->get()->getResultArray(), 'name'),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $route   = $this->route($id);
        $db      = db_connect();
        $actor   = (int) auth()->id();
        $posted  = (array) $this->request->getPost('steps');
        $trigger = (string) $this->request->getPost('variant_trigger');
        $value   = trim((string) $this->request->getPost('trigger_value'));
        $active  = (bool) $this->request->getPost('active');

        try {
            $steps = $this->parseSteps($posted, (int) $route['company_id']);

            if (! in_array($trigger, self::TRIGGERS, true)) {
                throw new RuntimeException('Choose a valid trigger.');
            }
            $variants = new RouteVariantModel();
            if ($variants->isStandard((string) $route['variant'])) {
                $trigger = 'none';
                $value   = '';
            }
            if ($trigger === 'amount_threshold' && (! is_numeric($value) || (float) $value <= 0)) {
                throw new RuntimeException('Enter the amount that switches this route on (a number above zero).');
            }
            if ($active) {
                if ($steps === []) {
                    throw new RuntimeException('Add at least one step before switching the route on.');
                }
                if (array_filter($steps, static fn (array $s): bool => in_array($s['step_type'], \App\Services\WorkflowEngine::ACTION_STEPS, true)) === []) {
                    throw new RuntimeException('A route needs at least one approve, process or pay step before it can be switched on.');
                }
                if (! $variants->isStandard((string) $route['variant']) && $trigger === 'none') {
                    throw new RuntimeException('Choose when this "' . $variants->name((string) $route['variant']) . '" route applies (a trigger) before switching it on.');
                }
            }
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $db->transBegin();
        try {
            $db->table('route_steps')->where('route_id', $id)->delete();
            foreach ($steps as $i => $s) {
                $db->table('route_steps')->insert([
                    'route_id'         => $id,
                    'step_order'       => $i + 1,
                    'step_type'        => $s['step_type'],
                    'job_title_id'     => $this->jobTitle($s['job_title']),
                    'assignee_kind'    => $s['assignee_kind'],
                    'assignee_user_id' => $s['user_id'],
                    'team_id'          => $s['team_id'],
                    'assignee_email'   => $s['email'],
                    'assignee_label'   => $s['label'],
                    'sla_days'         => $s['sla_days'],
                ]);
            }
            $db->table('routes')->where('id', $id)->update([
                'active'          => $active ? 1 : 0,
                'variant_trigger' => $trigger,
                'trigger_value'   => $trigger === 'amount_threshold' ? $value : null,
                'version'         => (int) $route['version'] + 1,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            if (! $db->transStatus()) {
                throw new RuntimeException('A database error occurred.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            return redirect()->back()->withInput()->with('error', 'Could not save: ' . $e->getMessage());
        }

        (new AuditLogger())->log('route.updated', $actor, 'route', $id, ['steps' => count($steps), 'active' => $active, 'trigger' => $trigger]);

        $redirect = redirect()->to('admin/routes/' . $id . '/edit')->with('message', 'Route saved.');
        $unseen   = $this->neverSignedIn($steps);
        if ($unseen !== []) {
            $redirect->with('warning', 'Saved, but ' . implode(', ', $unseen) . (count($unseen) === 1 ? ' has' : ' have')
                . ' not signed in yet. Invoices will wait on ' . 'them' . ' until they do (use "Invite" on the Users screen).');
        }

        return $redirect;
    }

    /**
     * Names of people on the saved steps (named users, and team members) who have never signed in.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return list<string>
     */
    private function neverSignedIn(array $steps): array
    {
        $ids = [];
        $db  = db_connect();
        foreach ($steps as $s) {
            if (! in_array($s['step_type'], \App\Services\WorkflowEngine::ACTION_STEPS, true)) {
                continue;
            }
            if ($s['assignee_kind'] === 'user' && $s['user_id'] !== null) {
                $ids[] = (int) $s['user_id'];
            } elseif ($s['assignee_kind'] === 'team' && $s['team_id'] !== null) {
                foreach ($db->table('team_members')->select('user_id')->where('team_id', (int) $s['team_id'])->get()->getResultArray() as $m) {
                    $ids[] = (int) $m['user_id'];
                }
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = $db->table('users')->select("COALESCE(NULLIF(display_name, ''), username) AS name", false)
            ->whereIn('id', array_values(array_unique($ids)))->where('last_active', null)->orderBy('name')->get()->getResultArray();

        return array_column($rows, 'name');
    }

    /**
     * Whether the user (or, for a team, any member) holds user_companies.can_approve for the company.
     */
    private function hasApprover(int $id, int $companyId, bool $isTeam): bool
    {
        // Not filtered on active: a user imported with the routes may not have signed in yet.
        $q = db_connect()->table('user_companies uc')->where(['uc.company_id' => $companyId, 'uc.can_approve' => 1]);
        if ($isTeam) {
            $q->join('team_members m', 'm.user_id = uc.user_id')->where('m.team_id', $id);
        } else {
            $q->where('uc.user_id', $id);
        }

        return $q->countAllResults() > 0;
    }

    /**
     * A route has at most one pay step, and it comes after every approve and process step (notify steps may follow it).
     *
     * @param list<array<string, mixed>> $steps
     *
     * @throws RuntimeException
     */
    private function assertPayStep(array $steps): void
    {
        $pay = array_keys(array_filter($steps, static fn (array $s): bool => $s['step_type'] === 'pay'));
        if ($pay === []) {
            return;
        }
        if (count($pay) > 1) {
            throw new RuntimeException('A route can have only one pay step.');
        }
        foreach ($steps as $i => $s) {
            if ($i > $pay[0] && in_array($s['step_type'], ['approve', 'process'], true)) {
                throw new RuntimeException('The pay step must come after every approve and process step.');
            }
        }
    }

    /**
     * @param array<int|string, mixed> $posted
     *
     * @return list<array{sla_days: int|null, step_type: string, job_title: string, assignee_kind: string, user_id: int|null, team_id: int|null, email: string|null, label: string|null}>
     */
    private function parseSteps(array $posted, int $companyId): array
    {
        $db    = db_connect();
        $steps = [];

        foreach ($posted as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['step_type'] ?? '');
            $kind = (string) ($row['assignee_kind'] ?? '');
            if (! in_array($type, self::STEP_TYPES, true) || ! in_array($kind, self::ASSIGNEE_KINDS, true)) {
                throw new RuntimeException('A step has an invalid type or assignee kind.');
            }

            $n      = count($steps) + 1;
            $userId = null;
            $teamId = null;
            $email  = trim((string) ($row['email'] ?? ''));
            $label  = trim((string) ($row['label'] ?? ''));

            if ($kind === 'team') {
                $teamId = (int) ($row['team_id'] ?? 0);
                $team   = $teamId > 0 ? $db->table('teams')->select('id, name, active, group_email')->where('id', $teamId)->get()->getRowArray() : null;
                if ($team === null) {
                    throw new RuntimeException("Step {$n}: choose a team.");
                }
                if (! $team['active']) {
                    throw new RuntimeException("Step {$n}: the team \"{$team['name']}\" is switched off.");
                }
                if (in_array($type, \App\Services\WorkflowEngine::ACTION_STEPS, true) && ! $this->hasApprover($teamId, $companyId, true)) {
                    throw new RuntimeException("Step {$n}: no one in the team \"{$team['name']}\" is allowed to approve for this company. Tick \"Can approve\" on a member's user record first.");
                }
                if ($type === 'notify' && $team['group_email'] === null && ! $db->table('team_members')->where('team_id', $teamId)->countAllResults()) {
                    throw new RuntimeException("Step {$n}: the team \"{$team['name']}\" has no members and no group mailbox, so no one would be told.");
                }
                $label = (string) $team['name'];
                $email = '';
            } elseif ($kind === 'user') {
                $userId = (int) ($row['user_id'] ?? 0);
                $user   = $userId > 0 ? $db->table('users')->select('id, display_name, username')->where('id', $userId)->get()->getRowArray() : null;
                if ($user === null) {
                    throw new RuntimeException("Step {$n}: choose a user.");
                }
                $label = (string) ($user['display_name'] ?: $user['username']);
                if (in_array($type, \App\Services\WorkflowEngine::ACTION_STEPS, true) && ! $this->hasApprover($userId, $companyId, false)) {
                    throw new RuntimeException("Step {$n}: {$label} is not allowed to approve for this company. Tick \"Can approve\" on their user record first.");
                }
                $email = '';
            } else {
                $userId = null;
                if ($label === '') {
                    throw new RuntimeException("Step {$n}: enter a name for the outside party.");
                }
                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException("Step {$n}: the email address is not valid.");
                }
            }

            // A blank Role on a user step is filled from that user's job title (this company's, else any).
            $title = trim((string) ($row['job_title'] ?? ''));
            if ($title === '' && $userId !== null) {
                $title = $this->userJobTitle($userId, $companyId);
            }

            $slaRaw = trim((string) ($row['sla_days'] ?? ''));
            $sla    = null;
            if ($slaRaw !== '') {
                if (! ctype_digit($slaRaw) || (int) $slaRaw < 1 || (int) $slaRaw > 365) {
                    throw new RuntimeException("Step {$n}: the time limit must be a whole number of days from 1 to 365, or blank for none.");
                }
                if (! in_array($type, \App\Services\WorkflowEngine::ACTION_STEPS, true)) {
                    throw new RuntimeException("Step {$n}: only approve, process and pay steps can have a time limit.");
                }
                $sla = (int) $slaRaw;
            }

            $steps[] = [
                'sla_days'      => $sla,
                'step_type'     => $type,
                'job_title'     => $title,
                'assignee_kind' => $kind,
                'user_id'       => $userId,
                'team_id'       => $teamId,
                'email'        => $email !== '' ? strtolower($email) : null,
                'label'         => $label !== '' ? $label : null,
            ];
        }

        $this->assertPayStep($steps);

        return $steps;
    }

    private function userJobTitle(int $userId, int $companyId): string
    {
        $rows = db_connect()->table('user_companies')->select('company_id, job_title')
            ->where('user_id', $userId)->where('job_title IS NOT NULL')->where("job_title <> ''")
            ->orderBy('company_id')->get()->getResultArray();

        foreach ($rows as $r) {
            if ((int) $r['company_id'] === $companyId) {
                return trim((string) $r['job_title']);
            }
        }

        return $rows === [] ? '' : trim((string) $rows[0]['job_title']);
    }

    private function jobTitle(string $name): ?int
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return null;
        }

        $db  = db_connect();
        $row = $db->table('job_titles')->where('LOWER(name)', strtolower($name))->get()->getRowArray();
        if ($row !== null) {
            return (int) $row['id'];
        }
        $db->table('job_titles')->insert(['name' => $name]);

        return (int) $db->insertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function route(int $id): array
    {
        $route = db_connect()->table('routes r')
            ->select('r.*, c.code, c.name AS company_name, t.name AS type_name, t.high_priority, t.default_type_id')
            ->join('companies c', 'c.id = r.company_id')
            ->join('invoice_types t', 't.id = r.invoice_type_id')
            ->where('r.id', $id)->get()->getRowArray();

        if ($route === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $route;
    }
}
