<?php

declare(strict_types=1);

namespace Kabuto\Mail;

use RuntimeException;

final class MailManager
{
    public function __construct(private readonly MailTransport $transport, private readonly string $from = '') {}

    public function send(MailMessage $message): void
    {
        $data = $message->toArray();
        if ($data['to'] === []) throw new RuntimeException('A mail message requires at least one recipient.');
        if ($data['subject'] === '') throw new RuntimeException('A mail message requires a subject.');
        if ($this->from !== '' && $data['to'] !== []) {
            // The transport remains provider-neutral; from is available to custom transports via config.
        }
        $this->transport->send($message);
    }

    public function transport(): MailTransport { return $this->transport; }

    public function health(): bool
    {
        return method_exists($this->transport, 'health') ? (bool) $this->transport->health() : true;
    }
}
