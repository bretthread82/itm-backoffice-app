<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\SharePoint\SyncService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The queue of completed invoices on their way to SharePoint.
 */
class SharePointSync extends BaseController
{
    private const PER_PAGE = 50;

    public function index(): string
    {
        $db     = db_connect();
        $status = (string) $this->request->getGet('status');
        $page   = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('sync_queue q')
            ->select('q.*, i.invoice_number, i.supplier, i.completed_at, i.sharepoint_url, c.code AS company_code, s.name AS connection_name')
            ->join('invoices i', 'i.id = q.invoice_id')->join('companies c', 'c.id = i.company_id')->join('sharepoint_connections s', 's.id = q.connection_id', 'left');
        if (in_array($status, ['pending', 'syncing', 'synced', 'failed', 'not_configured'], true)) {
            $builder->where('q.status', $status);
        } else {
            $status = '';
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('q.id', 'DESC')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        $counts = [];
        foreach ($db->query('SELECT status, COUNT(*) AS n FROM sync_queue GROUP BY status')->getResultArray() as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }

        return view('admin/sharepoint/sync', [
            'title'  => 'SharePoint sync',
            'rows'   => $rows,
            'counts' => $counts,
            'status' => $status,
            'page'   => $page,
            'pages'  => (int) max(1, ceil($total / self::PER_PAGE)),
            'max'    => SyncService::MAX_ATTEMPTS,
        ]);
    }

    public function retry(int $invoiceId): RedirectResponse
    {
        $outcome = (new SyncService())->retry($invoiceId, (int) auth()->id());

        return redirect()->back()->with($outcome === 'synced' ? 'message' : 'warning', match ($outcome) {
            'synced'         => 'Saved to SharePoint.',
            'retry'          => 'It failed again and will be retried automatically. See the error below.',
            'failed'         => 'It failed again. See the error below.',
            'not_configured' => 'Its company has no SharePoint connection that is switched on, so it is waiting.',
            default          => 'Nothing to do: it is already saved or being processed.',
        });
    }

    public function run(): RedirectResponse
    {
        $r = (new SyncService())->run(25);

        return redirect()->back()->with($r['failed'] > 0 ? 'warning' : 'message', "Processed {$r['processed']}: {$r['synced']} sent, {$r['retrying']} will retry, {$r['failed']} gave up, {$r['not_configured']} waiting for a connection.");
    }
}
