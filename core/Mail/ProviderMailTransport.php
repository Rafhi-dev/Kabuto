<?php

declare(strict_types=1);

namespace Kabuto\Mail;

final class ProviderMailTransport implements MailTransport
{
    public function __construct(private readonly MailProvider $provider) {}
    public function send(MailMessage $message): void { $this->provider->send($message); }
}
