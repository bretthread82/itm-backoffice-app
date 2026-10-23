<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * How system email leaves the app. SendGrid in production; a logging transport in development;
 * a fake in tests. A failed send must return a result, never throw, so workflow actions never fail
 * because email is down.
 */
interface MailTransport
{
    public function send(MailMessage $message): MailResult;
}
