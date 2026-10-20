<?php

declare(strict_types=1);

namespace Kabuto\Auth;

interface SessionStore
{
    /** @return array<string,mixed>|null */
    public function read(string $id): ?array;

    /** @param array<string,mixed> $data */
    public function write(string $id, array $data, int $expiresAt): void;

    public function delete(string $id): void;
}
