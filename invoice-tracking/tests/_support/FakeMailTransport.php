<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mail\MailMessage;
use App\Services\Mail\MailResult;
use App\Services\Mail\MailTransport;

final class FakeMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public bool $fail = false;

    public function send(MailMessage $message): MailResult
    {
        if ($this->fail) {
            return MailResult::failed('Simulated outage');
        }
        $this->sent[] = $message;

        return MailResult::sent('fake-' . count($this->sent));
    }

    /** @return list<MailMessage> */
    public function to(string $email): array
    {
        return array_values(array_filter($this->sent, static fn (MailMessage $m): bool => $m->to === strtolower($email)));
    }
}
