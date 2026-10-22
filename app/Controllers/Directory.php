<?php

namespace App\Controllers;

use App\Models\DepartmentModel;
use App\Models\StaffProfileModel;
use CodeIgniter\Exceptions\PageNotFoundException;

class Directory extends BaseController
{
    private const PER_PAGE = 24;

    public function index(): string
    {
        $filters = [
            'q'             => trim((string) $this->request->getGet('q')),
            'department_id' => (string) $this->request->getGet('department_id'),
            'location'      => (string) $this->request->getGet('location'),
            'status'        => 'active',
        ];

        $profiles = model(StaffProfileModel::class);
        $total    = $profiles->listing($filters)->countAllResults();
        $page     = max(1, (int) $this->request->getGet('page'));
        $rows     = $profiles->listing($filters)->orderBy('sp.last_name')->orderBy('sp.first_name')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        $locations = array_column(db_connect()->table('staff_profiles')->distinct()->select('location')
            ->where('location IS NOT NULL')->where('deleted_at', null)->orderBy('location')->get()->getResultArray(), 'location');

        return view('directory/index', [
            'title'       => 'Staff directory',
            'rows'        => $rows,
            'filters'     => $filters,
            'total'       => $total,
            'pager'       => service('pager')->makeLinks($page, self::PER_PAGE, $total, 'bootstrap'),
            'departments' => model(DepartmentModel::class)->orderBy('name')->findAll(),
            'locations'   => $locations,
        ]);
    }

    public function show(int $id): string
    {
        $profiles = model(StaffProfileModel::class);
        $person   = $profiles->listing()->where('sp.user_id', $id)->get()->getRowArray();

        if (! $person || $person['status'] !== 'active') {
            throw PageNotFoundException::forPageNotFound();
        }

        $manager = $person['manager_id'] ? $profiles->listing()->where('sp.user_id', $person['manager_id'])->get()->getRowArray() : null;
        $reports = $profiles->listing(['status' => 'active'])->where('sp.manager_id', $id)->orderBy('sp.last_name')->get()->getResultArray();

        return view('directory/show', ['title' => $person['display_name'] ?: $person['first_name'] . ' ' . $person['last_name'], 'person' => $person, 'manager' => $manager, 'reports' => $reports]);
    }
}
