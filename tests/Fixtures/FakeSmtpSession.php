<?php

declare(strict_types=1);

namespace Kabuto\Tests\Fixtures;

use Kabuto\Mail\SmtpSession;
use RuntimeException;

final class FakeSmtpSession implements SmtpSession
{
    /** @var list<array{code:int,lines:list<string>}> */
    private array $responses;
    /** @var list<string> */
    public array $writes = [];
    public int $tlsUpgrades = 0;
    public bool $closed = false;

    /** @param list<array{code:int,lines:list<string>}> $responses */
    public function __construct(array $responses, private readonly bool $tlsResult = true)
    {
        $this->responses = $responses;
    }

    public function readResponse(): array
    {
        if ($this->responses === []) {
            throw new RuntimeException('No fake SMTP response remains.');
        }
        return array_shift($this->responses);
    }

    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    public function enableTls(): bool
    {
        $this->tlsUpgrades++;
        return $this->tlsResult;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
