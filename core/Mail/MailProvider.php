<?php

declare(strict_types=1);

namespace Kabuto\Mail;

interface MailProvider
{
    public function send(MailMessage $message): void;
}
