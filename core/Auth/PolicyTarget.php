<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final readonly class PolicyTarget
{
    public function __construct(private mixed $value, private string $modelClass) {}
    public function value(): mixed { return $this->value; }
    public function modelClass(): string { return $this->modelClass; }
}
