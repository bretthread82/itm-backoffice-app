<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\StaffProfileModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Approval queue for self-service profile change requests.
 */
class Requests extends BaseController
{
    private const LABELS = ['job_title' => 'Job title', 'department_id' => 'Department', 'manager_id' => 'Manager'];

    public function index(): string
    {
        $status = in_array($this->request->getGet('status'), ['pending', 'approved', 'rejected'], true) ? $this->request->getGet('status') : 'pending';

        $rows = db_connect()->table('profile_change_requests r')
            ->select('r.*, sp.display_name, sp.first_name, sp.last_name, sp.job_title AS cur_title, sp.department_id AS cur_dept, sp.manager_id AS cur_mgr')
            ->join('staff_profiles sp', 'sp.user_id = r.user_id')
            ->where('r.status', $status)->orderBy('r.id', 'DESC')->limit(100)->get()->getResultArray();

        foreach ($rows as &$row) {
            $row['label']     = self::LABELS[$row['field']] ?? $row['field'];
            $row['current']   = $this->describe($row['field'], match ($row['field']) {
                'job_title' => $row['cur_title'], 'department_id' => $row['cur_dept'], default => $row['cur_mgr'],
            });
            $row['requested'] = $this->describe($row['field'], $row['new_value']);
        }

        return view('admin/requests/index', ['title' => 'Profile change requests', 'rows' => $rows, 'status' => $status]);
    }

    public function decide(int $id): RedirectResponse
    {
        $db  = db_connect();
        $req = $db->table('profile_change_requests')->where('id', $id)->get()->getRowArray()
            ?? throw PageNotFoundException::forPageNotFound();

        if ($req['status'] !== 'pending') {
            return redirect()->back()->with('error', 'That request has already been decided.');
        }

        // Nobody approves their own request.
        if ((int) $req['user_id'] === (int) auth()->id()) {
            return redirect()->back()->with('error', 'You cannot decide your own request.');
        }

        $approve = $this->request->getPost('decision') === 'approve';
        $note    = trim((string) $this->request->getPost('note')) ?: null;

        if ($approve) {
            $value = $req['field'] === 'job_title' ? $req['new_value'] : (int) $req['new_value'];

            // The target may have been deleted since the request was made.
            if ($req['field'] === 'department_id' && ! $db->table('departments')->where('id', $value)->countAllResults()) {
                return redirect()->back()->with('error', 'That department no longer exists. Reject the request instead.');
            }

            if ($req['field'] === 'manager_id' && ! $db->table('staff_profiles')->where('user_id', $value)->where('deleted_at', null)->countAllResults()) {
                return redirect()->back()->with('error', 'That manager no longer exists. Reject the request instead.');
            }

            model(StaffProfileModel::class)->update((int) $req['user_id'], [$req['field'] => $value]);
        }

        $db->table('profile_change_requests')->where('id', $id)->update([
            'status' => $approve ? 'approved' : 'rejected', 'decided_by' => auth()->id(),
            'decision_note' => $note, 'decided_at' => date('Y-m-d H:i:s'),
        ]);

        service('audit')->log('profile.request_' . ($approve ? 'approved' : 'rejected'), 'user', $req['user_id'], null, ['field' => $req['field'], 'new_value' => $req['new_value']]);
        service('notifier')->send(
            (int) $req['user_id'],
            'profile.request_decided',
            'Your ' . strtolower(self::LABELS[$req['field']] ?? $req['field']) . ' change request was ' . ($approve ? 'approved' : 'rejected'),
            $note,
            'profile',
        );

        return redirect()->to('admin/requests')->with('message', 'Request ' . ($approve ? 'approved' : 'rejected') . '.');
    }

    private function describe(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $db = db_connect();

        return match ($field) {
            'department_id' => (string) ($db->table('departments')->where('id', (int) $value)->get()->getRow('name') ?? '(deleted)'),
            'manager_id'    => (string) ($db->table('staff_profiles')->where('user_id', (int) $value)->get()->getRow('display_name') ?? '(deleted)'),
            default         => (string) $value,
        };
    }
}
