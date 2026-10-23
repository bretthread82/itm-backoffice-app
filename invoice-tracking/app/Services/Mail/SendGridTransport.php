<?php

declare(strict_types=1);

namespace App\Services\Mail;

use SendGrid;
use SendGrid\Mail\Mail;
use Throwable;

/**
 * SendGrid v3 Web API. The API key (restricted to "Mail Send") comes from .env as sendgrid.apiKey.
 */
final class SendGridTransport implements MailTransport
{
    public function __construct(private readonly string $apiKey)
    {
    }

    public function send(MailMessage $message): MailResult
    {
        if ($this->apiKey === '') {
            return MailResult::failed('No SendGrid API key is configured (sendgrid.apiKey).');
        }

        try {
            $mail = new Mail();
            $mail->setFrom($message->fromEmail, $message->fromName !== '' ? $message->fromName : null);
            $mail->setSubject($message->subject);
            $mail->addTo($message->to);
            $mail->addContent('text/html', $message->html);
            $mail->addContent('text/plain', trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>', '</li>'], "\n", $message->html)))), );
            if ($message->replyTo !== '') {
                $mail->setReplyTo($message->replyTo);
            }

            $response = (new SendGrid($this->apiKey))->send($mail);
            $status   = $response->statusCode();

            if ($status >= 200 && $status < 300) {
                $id = null;
                foreach ($response->headers() as $header) {
                    if (stripos((string) $header, 'X-Message-Id:') === 0) {
                        $id = trim(substr((string) $header, 13));
                        break;
                    }
                }

                return MailResult::sent($id);
            }

            return MailResult::failed('SendGrid returned HTTP ' . $status . ': ' . mb_substr((string) $response->body(), 0, 300));
        } catch (Throwable $e) {
            return MailResult::failed($e->getMessage());
        }
    }
}
