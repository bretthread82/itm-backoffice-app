<?php

namespace App\Core;

use Config\Backoffice;
use Throwable;

/**
 * The one place email leaves the application. Returns false (never throws)
 * when mail is not configured or sending fails, so callers can degrade.
 *
 * Development convenience: with `backoffice.mailLogOnly = true` (ignored in
 * production) messages are written to writable/logs/mail.log instead of being
 * sent, so flows such as password reset can be exercised without SMTP.
 */
class Mailer
{
    /**
     * True when a sender address is configured, i.e. mail can be sent.
     */
    public function isConfigured(): bool
    {
        return trim((string) config('Email')->fromEmail) !== '';
    }

    public function send(string $to, string $subject, string $html): bool
    {
        if (! $this->isConfigured()) {
            log_message('info', 'Email skipped: Config\\Email::$fromEmail is not set.');

            return false;
        }

        if ($this->logOnly()) {
            $line = '[' . date('Y-m-d H:i:s') . "] To: {$to} | Subject: {$subject}\n" . trim(strip_tags(str_replace(['</p>', '<br>', '</h2>'], "\n", $html))) . "\n---\n";
            file_put_contents(WRITEPATH . 'logs/mail.log', $line, FILE_APPEND | LOCK_EX);

            return true;
        }

        try {
            $email = service('email');
            $email->clear();
            $email->setTo($to)->setSubject($subject)->setMessage($html);

            if (! $email->send(false)) {
                log_message('error', 'Email to {to} failed: {debug}', ['to' => $to, 'debug' => $email->printDebugger(['headers'])]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            log_message('error', 'Email error: ' . $e->getMessage());

            return false;
        }
    }

    private function logOnly(): bool
    {
        return ENVIRONMENT !== 'production' && config(Backoffice::class)->mailLogOnly;
    }
}
