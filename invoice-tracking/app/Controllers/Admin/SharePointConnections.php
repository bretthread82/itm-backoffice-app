<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AuditLogger;
use App\Services\SharePoint\Connections;
use App\Services\SharePoint\SharePointException;
use App\Services\WorkflowException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Throwable;

/**
 * SharePoint connections (administrators only). Several are allowed and each company can be pointed at one
 * (Admin > Companies). Secrets are write-only: they are never shown again.
 */
class SharePointConnections extends BaseController
{
    public function index(): string
    {
        $rows = db_connect()->query(
            'SELECT s.*, (SELECT COUNT(*) FROM companies c WHERE c.sharepoint_connection_id = s.id) AS company_count
             FROM sharepoint_connections s ORDER BY s.name',
        )->getResultArray();

        $svc = new Connections();
        foreach ($rows as &$r) {
            $r['missing'] = $svc->missing($r);
        }
        unset($r);

        return view('admin/sharepoint/index', [
            'title'        => 'SharePoint connections',
            'connections'  => $rows,
            'noConnection' => db_connect()->table('companies')->where('active', 1)->where('sharepoint_connection_id', null)->countAllResults(),
        ]);
    }

    public function new(): string
    {
        return $this->form(['name' => '', 'tenant_id' => '', 'client_id' => '', 'site_url' => '', 'list_name' => '', 'library_name' => 'Documents', 'folder' => 'Invoices', 'field_mapping' => '', 'is_default' => 0, 'enabled' => 0, 'client_secret' => null, 'last_tested_at' => null, 'last_error' => null], null);
    }

    public function create(): RedirectResponse
    {
        return $this->persist(null);
    }

    public function edit(int $id): string
    {
        return $this->form($this->row($id), $id);
    }

    public function update(int $id): RedirectResponse
    {
        $this->row($id);

        return $this->persist($id);
    }

    /**
     * Checks the saved settings against the real SharePoint site. Changes nothing there.
     */
    public function test(int $id): RedirectResponse
    {
        $row     = $this->row($id);
        $svc     = new Connections();
        $missing = $svc->missing($row);
        if ($missing !== []) {
            return redirect()->to('admin/sharepoint/' . $id . '/edit')->with('error', 'Fill in the ' . implode(', ', $missing) . ' first.');
        }

        try {
            $result = service('sharepoint')->test($svc->forClient($row));
        } catch (SharePointException $e) {
            $svc->recordTest($id, $e->getMessage());
            (new AuditLogger())->log('sharepoint.test_failed', (int) auth()->id(), 'sharepoint_connection', $id);

            return redirect()->to('admin/sharepoint/' . $id . '/edit')->with('error', 'Test failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            $svc->recordTest($id, 'Unexpected error: ' . $e->getMessage());

            return redirect()->to('admin/sharepoint/' . $id . '/edit')->with('error', 'Test failed unexpectedly. See the log.');
        }

        $svc->recordTest($id, null);
        (new AuditLogger())->log('sharepoint.test_passed', (int) auth()->id(), 'sharepoint_connection', $id);

        return redirect()->to('admin/sharepoint/' . $id . '/edit')->with('message', 'Connected to "' . $result['site'] . '", library "' . $result['library'] . '"' . ($result['list'] ? ', list "' . $result['list'] . '"' : ' (files only, no list)') . '. You can now switch it on.');
    }

    public function delete(int $id): RedirectResponse
    {
        $this->row($id);

        try {
            (new Connections())->delete($id, (int) auth()->id());
        } catch (WorkflowException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/sharepoint')->with('message', 'Connection deleted.');
    }

    // ------------------------------------------------------------------ helpers

    private function persist(?int $id): RedirectResponse
    {
        try {
            $result = (new Connections())->save($this->request->getPost(), $id, (int) auth()->id());
        } catch (WorkflowException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if ($result['id'] === null || ($id === null && $result['errors'] !== [] && $this->hardErrors($result['errors']))) {
            return redirect()->back()->withInput()->with('errors', $result['errors']);
        }

        $to = redirect()->to('admin/sharepoint/' . $result['id'] . '/edit');

        return $result['errors'] === []
            ? $to->with('message', 'Saved. Press "Test connection" to check it against SharePoint.')
            : $to->with('warning', 'Saved, but: ' . implode(' ', $result['errors']));
    }

    /** True when the save was rejected outright (nothing stored), as opposed to "saved but left off". */
    private function hardErrors(array $errors): bool
    {
        foreach ($errors as $e) {
            if (! str_contains($e, 'cannot be switched on')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function form(array $row, ?int $id): string
    {
        $svc = new Connections();

        return view('admin/sharepoint/form', [
            'title'      => $id === null ? 'New SharePoint connection' : 'Edit connection',
            'c'          => $row,
            'id'         => $id,
            'hasSecret'  => ! empty($row['client_secret']),
            'mappingKeys' => Connections::MAPPING_KEYS,
            'defaultMapping' => json_encode(Connections::DEFAULT_MAPPING),
            'missing'    => $id === null ? [] : $svc->missing($row),
            'companies'  => $id === null ? [] : db_connect()->table('companies')->select('code')->where('sharepoint_connection_id', $id)->orderBy('code')->get()->getResultArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = (new Connections())->find($id);
        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $row;
    }
}
