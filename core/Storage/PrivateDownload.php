<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use Kabuto\Http\Response;

final class PrivateDownload
{
    public function __construct(private readonly StorageDisk $disk) {}

    public function response(string $path, ?callable $authorize = null): Response
    {
        if ($authorize === null || !$authorize($path)) return (new Response())->status(403)->send('Forbidden');
        $body = $this->disk->get($path);
        if ($body === null) return (new Response())->status(404)->send('Not Found');
        return (new Response())
            ->header('Content-Type', $this->disk->mime($path) ?? 'application/octet-stream')
            ->header('Content-Length', (string) strlen($body))
            ->header('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
            ->send($body);
    }
}
