<?php

declare(strict_types=1);

namespace App\Services\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $fromEmail,
        public readonly string $fromName = '',
        public readonly string $replyTo = '',
    ) {
    }
}
