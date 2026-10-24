<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\CustomerImporter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Admin > Customers: the global customer list used by every module (service('customers')). Maintaining it is
 * `customers.manage`; modules only read it. Records elsewhere reference a customer by id and keep a name snapshot,
 * so renaming or switching a customer off never alters history.
 */
class Customers extends BaseController
{
    private const FIELDS = [
        'code', 'name', 'legal_name', 'tax_id', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country', 'payment_terms_days', 'default_currency', 'notes',
    ];

    public function index(): string
    {
        return view('admin/customers/index', [
            'title'     => 'Customers',
            'customers' => service('customers')->all($this->usage()),
            'usage'     => array_keys($this->usage()),
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            $id = service('customers')->create($this->input(), (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/customers/' . $id)->with('message', 'Customer added. You can now add contacts.');
    }

    public function edit(int $id): string|RedirectResponse
    {
        $customer = service('customers')->find($id);

        if ($customer === null) {
            return redirect()->to('admin/customers')->with('error', 'Customer not found.');
        }

        return view('admin/customers/edit', ['title' => $customer['name'], 'customer' => $customer, 'contacts' => service('customers')->contacts($id)]);
    }

    public function update(int $id): RedirectResponse
    {
        try {
            service('customers')->update($id, $this->input() + ['active' => (bool) $this->request->getPost('active')], (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/customers/' . $id)->with('message', 'Customer saved.');
    }

    public function addContact(int $id): RedirectResponse
    {
        $data = [];
        foreach (['name', 'email', 'phone', 'role'] as $f) {
            $data[$f] = (string) $this->request->getPost($f);
        }
        $data['is_primary'] = (bool) $this->request->getPost('is_primary');
        $data['is_notify']  = (bool) $this->request->getPost('is_notify');

        try {
            service('customers')->addContact($id, $data, (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/customers/' . $id)->with('message', 'Contact added.');
    }

    public function notifyContact(int $id, int $contactId): RedirectResponse
    {
        try {
            service('customers')->setNotificationContact($id, $contactId, (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->to('admin/customers/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('admin/customers/' . $id)->with('message', 'Notification contact saved.');
    }

    public function clearNotifyContact(int $id): RedirectResponse
    {
        service('customers')->clearNotificationContact($id, (int) auth()->id());

        return redirect()->to('admin/customers/' . $id)->with('message', 'Notification contact cleared.');
    }

    public function removeContact(int $id, int $contactId): RedirectResponse
    {
        try {
            service('customers')->removeContact($id, $contactId, (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->to('admin/customers/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('admin/customers/' . $id)->with('message', 'Contact removed.');
    }

    // ------------------------------------------------------------------ bulk upload

    public function uploadForm(): string
    {
        return view('admin/customers/upload', ['title' => 'Bulk upload customers']);
    }

    public function template(): ResponseInterface
    {
        $csv = "code,name,email,phone,payment_terms_days,active\n"
            . "C0001,Harbour Foods Ltd,accounts@harbourfoods.example,555-0100,30,Y\n"
            . "C0002,Island Retail Co,ap@islandretail.example,,45,Y\n";

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="customers-template.csv"')
            ->setBody($csv);
    }

    public function uploadPreview(): RedirectResponse|string
    {
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', 'Choose a CSV file to upload.');
        }
        if ($file->getSize() > 1024 * 1024) {
            return redirect()->back()->with('error', 'The file is larger than 1 MB.');
        }
        if (strtolower($file->getClientExtension()) !== 'csv') {
            return redirect()->back()->with('error', 'Only .csv files are accepted.');
        }

        $store = service('fileStore');
        $token = bin2hex(random_bytes(16));
        $key   = 'bulk/' . $token . '.csv';
        $store->put($key, $file->getTempName());

        $importer = new CustomerImporter();
        try {
            $preview = $importer->validate($importer->parse((string) $store->localPath($key)));
        } catch (Throwable $e) {
            $store->delete($key);

            return redirect()->back()->with('error', 'Could not read the file: ' . $e->getMessage());
        }

        session()->set('customer_upload', ['token' => $token, 'user' => auth()->id()]);

        return view('admin/customers/upload_preview', ['title' => 'Check the upload', 'preview' => $preview]);
    }

    public function uploadCommit(): RedirectResponse
    {
        $state = session()->get('customer_upload');
        $key   = is_array($state) && preg_match('/^[a-f0-9]{32}$/', (string) $state['token']) ? 'bulk/' . $state['token'] . '.csv' : '';
        $path  = $key === '' ? null : service('fileStore')->localPath($key);
        if ($path === null || (int) $state['user'] !== (int) auth()->id()) {
            return redirect()->to('admin/customers/upload')->with('error', 'The upload has expired. Please upload the file again.');
        }

        $importer = new CustomerImporter();
        try {
            $r = $importer->commit($importer->parse($path), (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->to('admin/customers/upload')->with('error', $e->getMessage());
        }

        service('fileStore')->delete($key);
        session()->remove('customer_upload');

        return redirect()->to('admin/customers')->with('message', sprintf('Upload complete: %d customers created, %d updated.', $r['created'], $r['updated']));
    }
    /**
     * Usage counts declared by the enabled modules that reference customers ('customerUsage' in the registry), so this
     * screen never has to know about any one module's tables.
     *
     * @return array<string, string>
     */
    private function usage(): array
    {
        $usage = [];

        foreach (config('Backoffice')->modules as $key => $module) {
            if (! empty($module['customerUsage']) && service('modules')->enabled((string) $key)) {
                $usage += $module['customerUsage'];
            }
        }

        return $usage;
    }

    /** @return array<string, string> */
    private function input(): array
    {
        $in = [];
        foreach (self::FIELDS as $f) {
            $in[$f] = (string) $this->request->getPost($f);
        }

        return $in;
    }
}
