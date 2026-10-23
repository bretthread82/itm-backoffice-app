<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * For the load balancer: 200 when this server can reach the database and the file store, 503 otherwise. Public, no
 * session, and it says nothing about what failed (the reason goes to the log).
 */
class Health extends BaseController
{
    public function index(): ResponseInterface
    {
        $ok = true;

        try {
            db_connect()->query('SELECT 1');
        } catch (Throwable $e) {
            log_message('error', 'Health check: database unreachable: ' . $e->getMessage());
            $ok = false;
        }

        try {
            service('fileStore')->exists('invoices/health-check'); // false is fine; an exception is not
        } catch (Throwable $e) {
            log_message('error', 'Health check: file store unreachable: ' . $e->getMessage());
            $ok = false;
        }

        return $this->response
            ->setStatusCode($ok ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $ok ? 'ok' : 'unavailable']);
    }
}
