<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Backups\DestinationFactory;
use App\Core\Backups\DestinationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Throwable;

/**
 * Add, change, test and remove the places backups are sent. Settings are stored encrypted;
 * secrets are never shown again after saving.
 */
class BackupDestinations extends BaseController
{
    private DestinationService $service;

    public function __construct()
    {
        $this->service = new DestinationService();
    }

    public function create(): string|RedirectResponse
    {
        $type = (string) $this->request->getGet('type');

        if (! isset(DestinationFactory::types()[$type])) {
            return view('admin/backups/pick_type', ['title' => 'Add a destination', 'types' => DestinationFactory::types()]);
        }

        return view('admin/backups/destination_form', [
            'title'      => 'Add ' . DestinationFactory::types()[$type]['label'],
            'type'       => $type,
            'def'        => DestinationFactory::types()[$type],
            'row'        => null,
            'values'     => [],
            'secretsSet' => [],
        ]);
    }

    public function store(): RedirectResponse
    {
        $type = (string) $this->request->getPost('type');

        if (! isset(DestinationFactory::types()[$type])) {
            return redirect()->to(site_url('admin/backups/destinations/new'))->with('error', 'Choose a destination type.');
        }

        if (! $this->validate($this->service->rules($type, false))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $id = $this->service->create($type, $this->request->getPost(), (int) auth()->id());
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/backups'))->with('message', 'Destination saved. Press Test to check it works.');
    }

    public function edit(int $id): string
    {
        $row = $this->service->find($id) ?? throw PageNotFoundException::forPageNotFound();

        try {
            $form = $this->service->formValues($row);
        } catch (Throwable $e) {
            $form = ['values' => [], 'secretsSet' => []];
            session()->setFlashdata('error', 'The saved settings could not be read (' . $e->getMessage() . '). Enter them again.');
        }

        return view('admin/backups/destination_form', [
            'title'      => 'Edit ' . $row['name'],
            'type'       => $row['type'],
            'def'        => DestinationFactory::types()[$row['type']],
            'row'        => $row,
            'values'     => $form['values'],
            'secretsSet' => $form['secretsSet'],
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $row = $this->service->find($id) ?? throw PageNotFoundException::forPageNotFound();

        if (! $this->validate($this->service->rules($row['type'], true))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $this->service->update($id, $this->request->getPost(), (int) auth()->id());
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/backups'))->with('message', 'Destination updated. Press Test to check it works.');
    }

    public function test(int $id): RedirectResponse
    {
        if (! $this->service->find($id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $error = $this->service->test($id);

        return redirect()->to(site_url('admin/backups'))->with($error === null ? 'message' : 'error', $error === null ? 'The destination works: a test file was written and removed.' : 'Test failed: ' . $error);
    }

    public function delete(int $id): RedirectResponse
    {
        $this->service->delete($id, (int) auth()->id());

        return redirect()->to(site_url('admin/backups'))->with('message', 'Destination removed. Files already sent there are left in place.');
    }
}
