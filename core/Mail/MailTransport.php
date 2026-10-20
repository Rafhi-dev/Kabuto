<?php

declare(strict_types=1);

namespace Kabuto\Mail;

interface MailTransport
{
    public function send(MailMessage $message): void;
}
