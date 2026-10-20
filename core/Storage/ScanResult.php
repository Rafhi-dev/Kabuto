<?php

declare(strict_types=1);

namespace Kabuto\Storage;

final readonly class ScanResult
{
    public function __construct(
        public bool $clean,
        public ?string $threat = null,
        public ?string $scannerVersion = null,
    ) {
    }

    public static function clean(?string $scannerVersion = null): self
    {
        return new self(true, null, $scannerVersion);
    }

    public static function infected(string $threat, ?string $scannerVersion = null): self
    {
        return new self(false, $threat, $scannerVersion);
    }
}
