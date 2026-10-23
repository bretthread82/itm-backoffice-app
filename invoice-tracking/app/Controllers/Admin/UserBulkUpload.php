<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\InvitationService;
use App\Services\UserBulkImporter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Two-step bulk provisioning: upload -> validation preview -> confirm. A file with any error
 * writes nothing. The uploaded file is kept in writable/ (outside the web root) between the
 * steps, referenced by a random token that is bound to the admin's session.
 */
class UserBulkUpload extends BaseController
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function index(): string
    {
        return view('admin/users/upload', ['title' => 'Bulk upload users']);
    }

    public function template(): ResponseInterface
    {
        $csv = "email,display_name,company_codes,job_title,can_submit,can_approve,group_level,is_admin,active\n"
            . "jane.doe@example.com,Jane Doe,HCSRL;CHL,Accountant,Y,Y,,,\n"
            . "sam.lee@example.com,Sam Lee,RHC,Accounts Clerk,N,N,,,\n";

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="user-upload-template.csv"')
            ->setBody($csv);
    }

    public function preview(): RedirectResponse|string
    {
        $file = $this->request->getFile('file');

        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', 'Choose a CSV or XLSX file to upload.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return redirect()->back()->with('error', 'The file is larger than 5 MB.');
        }

        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, ['csv', 'xlsx'], true)) {
            return redirect()->back()->with('error', 'Only .csv and .xlsx files are accepted.');
        }

        $store = service('fileStore');
        $token = bin2hex(random_bytes(16));
        $key   = 'bulk/' . $token . '.' . $ext;
        $store->put($key, $file->getTempName());

        $importer = new UserBulkImporter();
        try {
            $rows    = $importer->parse((string) $store->localPath($key));
            $preview = $importer->validate($rows);
        } catch (Throwable $e) {
            $store->delete($key);

            return redirect()->back()->with('error', 'Could not read the file: ' . $e->getMessage());
        }

        session()->set('bulk_upload', ['token' => $token, 'ext' => $ext, 'user' => auth()->id()]);

        return view('admin/users/upload_preview', [
            'title'   => 'Check the upload',
            'preview' => $preview,
            'replace' => (bool) $this->request->getPost('replace'),
            'invite'  => (bool) $this->request->getPost('invite'),
        ]);
    }

    public function commit(): RedirectResponse|string
    {
        $state = session()->get('bulk_upload');
        if (! is_array($state) || (int) $state['user'] !== (int) auth()->id()) {
            return redirect()->to('admin/users/upload')->with('error', 'The upload has expired. Please upload the file again.');
        }

        $key  = 'bulk/' . $state['token'] . '.' . $state['ext'];
        $path = preg_match('/^[a-f0-9]{32}$/', (string) $state['token']) === 1 && in_array($state['ext'], ['csv', 'xlsx'], true)
            ? service('fileStore')->localPath($key)
            : null;
        if ($path === null) {
            return redirect()->to('admin/users/upload')->with('error', 'The upload has expired. Please upload the file again.');
        }

        $importer = new UserBulkImporter();

        try {
            $result = $importer->commit($importer->parse($path), (int) auth()->id(), (bool) $this->request->getPost('replace'));
        } catch (RuntimeException $e) {
            return redirect()->to('admin/users/upload')->with('error', $e->getMessage());
        }

        service('fileStore')->delete($key);
        session()->remove('bulk_upload');

        $invited = $failed = 0;
        if ($this->request->getPost('invite')) {
            $svc = new InvitationService();
            foreach ($result['created_ids'] as $userId) {
                $svc->send($userId, (int) auth()->id()) ? $invited++ : $failed++;
            }
        }

        return view('admin/users/upload_result', [
            'title'   => 'Upload complete',
            'result'  => $result,
            'invited' => $invited,
            'failed'  => $failed,
            'invite'  => (bool) $this->request->getPost('invite'),
        ]);
    }
}
