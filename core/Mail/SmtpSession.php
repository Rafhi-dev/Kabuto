<?php

declare(strict_types=1);

namespace Kabuto\Mail;

/** Minimal SMTP wire interface, also usable by deterministic test sessions. */
interface SmtpSession
{
    /** @return array{code:int,lines:list<string>} */
    public function readResponse(): array;
    public function write(string $data): void;
    public function enableTls(): bool;
    public function close(): void;
}
