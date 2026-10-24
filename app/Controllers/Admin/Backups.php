<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Backups\BackupLauncher;
use App\Core\Backups\BackupStatus;
use App\Core\Backups\DestinationFactory;
use App\Core\Backups\DestinationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Backups as BackupsConfig;

/**
 * Backup status for managers and admins; Run now for those who may manage backups.
 * Destinations are handled by BackupDestinations.
 */
class Backups extends BaseController
{
    private const PER_PAGE = 20;

    public function index(): string
    {
        $status = new BackupStatus();
        $config = config(BackupsConfig::class);
        $page   = max(1, (int) $this->request->getGet('page'));
        $db     = db_connect();

        $total = $db->table('bk_runs')->countAllResults();
        $runs  = $db->table('bk_runs r')
            ->select('r.*, sp.display_name AS started_by')
            ->join('staff_profiles sp', 'sp.user_id = r.triggered_by', 'left')
            ->orderBy('r.id', 'DESC')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ->get()->getResultArray();

        $destinations = can('backups.manage') ? (new DestinationService())->all() : [];
        $types        = DestinationFactory::types();

        return view('admin/backups/index', [
            'title'        => 'Backups',
            'subtitle'     => 'Database backups, encrypted and sent off the server',
            'last'         => $status->lastRun(),
            'lastGood'     => $status->lastGood(),
            'stale'        => $status->isStale(),
            'health'       => $status->destinationHealth(),
            'running'      => (new BackupLauncher())->isRunning(),
            'encryption'   => $config->encryptionReady(),
            'staleHours'   => $config->staleHours,
            'runs'         => $runs,
            'destinations' => $destinations,
            'types'        => $types,
            'pager'        => service('pager')->makeLinks($page, self::PER_PAGE, $total, 'bootstrap'),
        ]);
    }

    public function show(int $id): string
    {
        $db  = db_connect();
        $run = $db->table('bk_runs r')
            ->select('r.*, sp.display_name AS started_by')
            ->join('staff_profiles sp', 'sp.user_id = r.triggered_by', 'left')
            ->where('r.id', $id)->get()->getRowArray();

        if (! $run) {
            throw PageNotFoundException::forPageNotFound();
        }

        $targets = $db->table('bk_run_destinations d')
            ->select('d.*, x.name, x.type')
            ->join('bk_destinations x', 'x.id = d.destination_id')
            ->where('d.run_id', $id)->orderBy('x.name')->get()->getResultArray();

        return view('admin/backups/show', [
            'title'   => 'Backup #' . $run['id'],
            'run'     => $run,
            'targets' => $targets,
            'types'   => DestinationFactory::types(),
        ]);
    }

    public function run(): RedirectResponse
    {
        $destination = (int) $this->request->getPost('destination_id') ?: null;

        if ($destination !== null && ! (new DestinationService())->find($destination)) {
            return redirect()->to(site_url('admin/backups'))->with('error', 'That destination no longer exists.');
        }

        $error = (new BackupLauncher())->start((int) auth()->id(), $destination);

        if ($error !== null) {
            return redirect()->to(site_url('admin/backups'))->with('error', $error);
        }

        service('audit')->log('backups.run_requested', 'backups', null, null, ['destination' => $destination]);

        return redirect()->to(site_url('admin/backups'))->with('message', 'Backup started. Refresh this page in a minute to see the result.');
    }
}
