<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AuditLogger;
use App\Services\Entra\EntraConfig;
use App\Services\LogoStore;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * System settings an administrator can change without a deployment.
 */
class Settings extends BaseController
{
    public function index(): string
    {
        return view('admin/settings/index', [
            'title' => 'Settings',
            'hasGroupLogo' => (string) setting('AppSettings.groupLogoFile') !== '',
            'entra' => [
                'enabled'      => EntraConfig::enabled(),
                'problems'     => EntraConfig::problems(),
                'tenant'       => EntraConfig::tenant(),
                'clientId'     => EntraConfig::clientId(),
                'hasSecret'    => EntraConfig::clientSecret() !== '',
                'redirectUri'  => EntraConfig::redirectUri(),
                'linked'       => db_connect()->table('users')->where('entra_oid IS NOT NULL', null, false)->countAllResults(),
            ],
            's'     => [
                'mailFromEmail'   => setting('AppSettings.mailFromEmail'),
                'mailFromName'    => setting('AppSettings.mailFromName'),
                'mailReplyTo'     => setting('AppSettings.mailReplyTo'),
                'maxUploadMb'     => setting('AppSettings.maxUploadMb'),
                'digestHour'      => setting('AppSettings.digestHour'),
                'defaultCurrency' => setting('AppSettings.defaultCurrency'),
                'retentionDays'   => setting('AppSettings.retentionDays'),
            ],
        ]);
    }

    /**
     * Switch Microsoft sign-in on or off. It can only be switched on once .env is configured.
     * Local email/password sign-in is not touched either way.
     */
    public function saveMicrosoft(): RedirectResponse
    {
        $want = (bool) $this->request->getPost('enabled');

        if ($want && ! EntraConfig::configured()) {
            return redirect()->to('admin/settings')->with('errors', array_merge(['Microsoft sign-in cannot be switched on yet:'], EntraConfig::problems()));
        }

        $was = EntraConfig::enabled();
        setting()->set('AppSettings.entraEnabled', $want);

        if ($want !== $was) {
            (new AuditLogger())->log($want ? 'settings.microsoft_enabled' : 'settings.microsoft_disabled', (int) auth()->id());
        }

        return redirect()->to('admin/settings')->with('message', $want ? 'Microsoft sign-in is on. Users with a matching email can now use the "Sign in with Microsoft" button.' : 'Microsoft sign-in is off. Everyone signs in with email and password.');
    }

    /** Upload, replace or remove the group logo (shown when all companies are selected). */
    public function saveLogo(): RedirectResponse
    {
        $store   = new LogoStore();
        $current = (string) setting('AppSettings.groupLogoFile');
        $file    = $this->request->getFile('logo');

        if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            $problem = $store->validate($file);
            if ($problem !== null) {
                return redirect()->to('admin/settings')->with('error', $problem);
            }
            $saved = $store->store($file);
            $store->delete($current);
            setting()->set('AppSettings.groupLogoFile', $saved['file']);
            setting()->set('AppSettings.groupLogoMime', $saved['mime']);
            $message = 'Group logo saved.';
        } elseif ($this->request->getPost('remove_logo') && $current !== '') {
            $store->delete($current);
            setting()->set('AppSettings.groupLogoFile', '');
            setting()->set('AppSettings.groupLogoMime', '');
            $message = 'Group logo removed.';
        } else {
            return redirect()->to('admin/settings')->with('error', 'Choose an image to upload.');
        }

        (new AuditLogger())->log('settings.group_logo_changed', (int) auth()->id());

        return redirect()->to('admin/settings')->with('message', $message);
    }

    public function save(): RedirectResponse
    {
        $rules = [
            'mailFromEmail'   => 'required|valid_email|max_length[254]',
            'mailFromName'    => 'required|max_length[100]',
            'mailReplyTo'     => 'permit_empty|valid_email|max_length[254]',
            'maxUploadMb'     => 'required|is_natural_no_zero|less_than_equal_to[100]',
            'digestHour'      => 'required|is_natural|less_than_equal_to[23]',
            'defaultCurrency' => 'required|exact_length[3]|alpha',
            'retentionDays'   => 'required|is_natural_no_zero|less_than_equal_to[3650]',
        ];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $p = $this->request->getPost();

        setting()->set('AppSettings.mailFromEmail', strtolower(trim((string) $p['mailFromEmail'])));
        setting()->set('AppSettings.mailFromName', trim((string) $p['mailFromName']));
        setting()->set('AppSettings.mailReplyTo', strtolower(trim((string) ($p['mailReplyTo'] ?? ''))));
        setting()->set('AppSettings.maxUploadMb', (int) $p['maxUploadMb']);
        setting()->set('AppSettings.digestHour', (int) $p['digestHour']);
        setting()->set('AppSettings.defaultCurrency', strtoupper(trim((string) $p['defaultCurrency'])));
        setting()->set('AppSettings.retentionDays', (int) $p['retentionDays']);

        // Invitations and sign-in links (Shield) use CodeIgniter's own email settings: keep them in step.
        setting()->set('Email.fromEmail', strtolower(trim((string) $p['mailFromEmail'])));
        setting()->set('Email.fromName', trim((string) $p['mailFromName']));

        (new AuditLogger())->log('settings.updated', (int) auth()->id());

        return redirect()->to('admin/settings')->with('message', 'Settings saved.');
    }
}
