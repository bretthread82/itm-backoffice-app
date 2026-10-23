<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Development transport: writes the email to the application log instead of sending it.
 */
final class LogTransport implements MailTransport
{
    public function send(MailMessage $message): MailResult
    {
        log_message('info', 'MAIL to={to} subject={subject}', ['to' => $message->to, 'subject' => $message->subject]);

        return MailResult::sent('logged');
    }
}
