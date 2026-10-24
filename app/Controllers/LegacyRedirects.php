<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Old bookmarks and saved notification links from when the Payables module was called Invoices.
 */
final class LegacyRedirects extends BaseController
{
    public function invoices(string $rest = ''): RedirectResponse
    {
        return $this->moved('payables', $rest);
    }

    public function invoicesAdmin(string $rest = ''): RedirectResponse
    {
        return $this->moved('payables-admin', $rest);
    }

    private function moved(string $prefix, string $rest): RedirectResponse
    {
        $query = (string) $this->request->getUri()->getQuery();

        return redirect()->to($prefix . ($rest !== '' ? '/' . $rest : '') . ($query !== '' ? '?' . $query : ''), 301);
    }
}
