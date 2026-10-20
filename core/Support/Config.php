<?php

declare(strict_types=1);

namespace Kabuto\Support;

/**
 * Simple configuration repository loaded from PHP files in a directory.
 * Supports dot-notation access: config('app.name').
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $items = [];

    /**
     * Load every `*.php` file in the given directory. Each file must return
     * an array; its basename becomes the top-level config key.
     */
    public function load(string $directory): self
    {
        $directory = rtrim($directory, '/\\');

        if (!is_dir($directory)) {
            return $this;
        }

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $data = require $file;

            if (is_array($data)) {
                $this->items[$key] = $data;
            }
        }

        return $this;
    }

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    /**
     * @param array<string,mixed> $items
     */
    public function merge(array $items): void
    {
        $this->items = array_replace_recursive($this->items, $items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
