<?php

declare(strict_types=1);

namespace Kabuto\Support;

/**
 * Zero-dependency `.env` loader.
 *
 * Supported syntax: KEY=value, single/double quotes, `#` comments,
 * `export KEY=...` prefix and `${OTHER}` interpolation. Values already
 * present in the real environment always win and are never overwritten.
 */
final class Dotenv
{
    /**
     * Load `<basePath>/.env` into $_SERVER/$_ENV/getenv().
     * Silently does nothing when the file does not exist.
     */
    public static function load(string $basePath, string $file = '.env'): void
    {
        $path = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            return;
        }

        foreach (self::parse($path) as $key => $value) {
            if (array_key_exists($key, $_SERVER) || array_key_exists($key, $_ENV)) {
                continue;
            }

            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    /**
     * Fail fast when any of the given variables is missing or empty.
     *
     * @param list<string> $keys
     */
    public static function required(array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            if ($value === false || $value === null || trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Missing required environment variables: %s. Copy .env.example to .env and fill them in.',
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @return array<string,string>
     */
    public static function parse(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new \RuntimeException(sprintf('Unable to read env file [%s].', $path));
        }

        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with(strtolower($line), 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');

            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            [$value, $expandable] = self::unquote($value);
            $values[$key] = $expandable ? self::interpolate($value, $values) : $value;
        }

        return $values;
    }

    /**
     * @return array{0:string,1:bool} value + whether ${} interpolation applies
     */
    private static function unquote(string $value): array
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if ($first === '"' && $last === '"') {
                $inner = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], substr($value, 1, -1));
                return [$inner, true];
            }

            if ($first === "'" && $last === "'") {
                return [substr($value, 1, -1), false];
            }
        }

        $hash = strpos($value, ' #');

        if ($hash !== false) {
            return [trim(substr($value, 0, $hash)), true];
        }

        return [$value, true];
    }

    /**
     * @param array<string,string> $values
     */
    private static function interpolate(string $value, array $values): string
    {
        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)}/',
            static function (array $matches) use ($values): string {
                $key = $matches[1];

                if (array_key_exists($key, $values)) {
                    return $values[$key];
                }

                $env = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

                return $env === false || $env === null ? '' : (string) $env;
            },
            $value,
        ) ?? $value;
    }
}
