<?php

declare(strict_types=1);

namespace Kabuto\Storage;

final class FileRateLimitStore implements RateLimitStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) mkdir($directory, 0700, true);
    }

    public function hit(string $key, int $decaySeconds): array
    {
        $path = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $fp = fopen($path, 'c+');
        if ($fp === false) return ['count' => 1, 'reset' => time() + $decaySeconds];
        flock($fp, LOCK_EX);
        $data = json_decode(stream_get_contents($fp) ?: '', true);
        $now = time();
        $state = is_array($data) && (int) ($data['reset'] ?? 0) > $now
            ? ['count' => (int) ($data['count'] ?? 0) + 1, 'reset' => (int) $data['reset']]
            : ['count' => 1, 'reset' => $now + $decaySeconds];
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state)); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
        return $state;
    }
}
