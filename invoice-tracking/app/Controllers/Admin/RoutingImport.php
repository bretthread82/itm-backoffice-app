<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AuditLogger;
use App\Services\Routing\RoutingImporter;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;
use Throwable;

/**
 * Upload the SharePoint "Approval Routing" export, review what would happen, then commit.
 * Nothing is written to companies/routes/users until an administrator confirms.
 */
class RoutingImport extends BaseController
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function index(): string
    {
        $batches = db_connect()->table('routing_import_batches b')
            ->select('b.*, u.display_name AS uploader')
            ->join('users u', 'u.id = b.uploaded_by', 'left')
            ->orderBy('b.id', 'DESC')->limit(30)->get()->getResultArray();

        return view('admin/import/index', ['title' => 'Import routing', 'batches' => $batches]);
    }

    public function upload(): RedirectResponse
    {
        $file = $this->request->getFile('file');

        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', 'Choose a CSV or XLSX Approval Routing list.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return redirect()->back()->with('error', 'The file is larger than 10 MB.');
        }
        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, ['csv', 'xlsx'], true)) {
            return redirect()->back()->with('error', 'Only .csv and .xlsx files are accepted. Export the SharePoint list to Excel or CSV.');
        }

        $store  = service('fileStore');
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $store->put('imports/' . $stored, $file->getTempName());

        // Fail early if it cannot be read as a routing sheet.
        try {
            (new RoutingImporter())->analyse((string) $store->localPath('imports/' . $stored));
        } catch (Throwable $e) {
            $store->delete('imports/' . $stored);

            return redirect()->back()->with('error', 'Could not read the file: ' . $e->getMessage());
        }

        $db = db_connect();
        $db->table('routing_import_batches')->insert([
            'filename'    => $file->getClientName(),
            'stored_name' => $stored,
            'uploaded_by' => (int) auth()->id(),
            'status'      => 'uploaded',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        (new AuditLogger())->log('routing.import_uploaded', (int) auth()->id(), 'routing_import_batch', $id);

        return redirect()->to('admin/import/' . $id)->with('message', 'File uploaded. Review what will happen below. Nothing has been imported yet.');
    }

    public function review(int $id): string|RedirectResponse
    {
        $batch = $this->batch($id);

        if ($batch['status'] !== 'uploaded') {
            return view('admin/import/done', ['title' => 'Import', 'batch' => $batch, 'summary' => json_decode((string) $batch['summary'], true) ?: []]);
        }

        try {
            $analysis = (new RoutingImporter())->analyse($this->path($batch));
        } catch (Throwable $e) {
            return redirect()->to('admin/import')->with('error', 'Could not read the file: ' . $e->getMessage());
        }

        $levels = ['attention' => [], 'warning' => [], 'info' => []];
        foreach ($analysis['issues'] as $issue) {
            $levels[$issue['level']][] = $issue;
        }

        return view('admin/import/review', [
            'title'    => 'Review import',
            'batch'    => $batch,
            'analysis' => $analysis,
            'levels'   => $levels,
        ]);
    }

    public function commit(int $id): RedirectResponse
    {
        $batch = $this->batch($id);
        if ($batch['status'] !== 'uploaded') {
            return redirect()->to('admin/import')->with('error', 'This import has already been processed.');
        }

        try {
            $summary = (new RoutingImporter())->commit($this->path($batch), (int) auth()->id(), (bool) $this->request->getPost('overwrite'));
        } catch (RuntimeException $e) {
            return redirect()->to('admin/import/' . $id)->with('error', $e->getMessage());
        }

        db_connect()->table('routing_import_batches')->where('id', $id)->update([
            'status'       => 'committed',
            'summary'      => json_encode($summary),
            'committed_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to('admin/import/' . $id)->with('message', 'Import complete.');
    }

    public function discard(int $id): RedirectResponse
    {
        $batch = $this->batch($id);
        if ($batch['status'] === 'uploaded') {
            service('fileStore')->delete('imports/' . basename((string) $batch['stored_name']));
            db_connect()->table('routing_import_batches')->where('id', $id)->update(['status' => 'discarded']);
            (new AuditLogger())->log('routing.import_discarded', (int) auth()->id(), 'routing_import_batch', $id);
        }

        return redirect()->to('admin/import')->with('message', 'Import discarded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function batch(int $id): array
    {
        $batch = db_connect()->table('routing_import_batches')->where('id', $id)->get()->getRowArray();
        if ($batch === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $batch;
    }

    /**
     * @param array<string, mixed> $batch
     */
    private function path(array $batch): string
    {
        $path = service('fileStore')->localPath('imports/' . basename((string) $batch['stored_name']));
        if ($path === null) {
            throw new RuntimeException('The uploaded file is no longer available. Please upload it again.');
        }

        return $path;
    }
}
