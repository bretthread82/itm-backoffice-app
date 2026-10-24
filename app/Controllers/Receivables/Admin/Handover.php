<?php

declare(strict_types=1);

namespace App\Controllers\Receivables\Admin;

use App\Controllers\BaseController;
use App\Core\Receivables\ArUserOffboarding;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * Hand over a leaving person's customer invoice work to a replacement. Thin: ArUserOffboarding decides what moves and
 * who may do it. Switching the account off as well is offered only to people who may deactivate accounts.
 */
class Handover extends BaseController
{
    public function index(): string
    {
        $from = (int) $this->request->getGet('from');
        $to   = (int) $this->request->getGet('to');
        $svc  = new ArUserOffboarding();

        $plan    = $from > 0 ? $svc->preview($from) : null;
        $missing = $plan !== null && $to > 0 && $to !== $from ? $svc->missingFor($to, $plan) : [];

        return view('receivables/admin/handover', [
            'title'      => 'Hand over customer invoice work',
            'users'      => db_connect()->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) AS name, active FROM inv_users ORDER BY active DESC, name")->getResultArray(),
            'from'       => $from,
            'to'         => $to,
            'plan'       => $plan,
            'missing'    => $missing,
            'canDisable' => can('users.delete'),
        ]);
    }

    public function transfer(): RedirectResponse
    {
        $from = (int) $this->request->getPost('from');
        $to   = (int) $this->request->getPost('to');

        try {
            $r = (new ArUserOffboarding())->transfer((int) auth()->id(), $from, $to, (bool) $this->request->getPost('disable'));
        } catch (RuntimeException $e) {
            return redirect()->to('receivables-admin/handover?' . http_build_query(['from' => $from, 'to' => $to]))->with('error', $e->getMessage());
        }

        return redirect()->to('receivables-admin/handover')->with('message', sprintf(
            'Handed over: %d approval step%s on invoices in progress, %d route step%s, %d unfinished invoice%s, %d accepted invoice%s returned to their team, %d delegation%s ended.%s',
            $r['steps'],
            $r['steps'] === 1 ? '' : 's',
            $r['route_steps'],
            $r['route_steps'] === 1 ? '' : 's',
            $r['submitted'],
            $r['submitted'] === 1 ? '' : 's',
            $r['claims'],
            $r['claims'] === 1 ? '' : 's',
            $r['delegations'],
            $r['delegations'] === 1 ? '' : 's',
            $r['disabled'] ? ' The account was switched off and removed from ' . $r['teams'] . ' team' . ($r['teams'] === 1 ? '' : 's') . '.' : '',
        ));
    }
}
