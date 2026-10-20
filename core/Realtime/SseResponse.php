<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

use Kabuto\Http\Response;

final class SseResponse
{
    /** Stream headers for an SSE connection whose body chunks are written by SseStream->run(). */
    public static function headers(): Response
    {
        return (new Response())
            ->header('Content-Type', 'text/event-stream; charset=utf-8')
            ->header('Cache-Control', 'no-cache')
            ->header('Connection', 'keep-alive')
            ->header('X-Accel-Buffering', 'no')
            ->send('');
    }

    /** @param list<RealtimeMessage> $messages */
    public static function from(array $messages): Response
    {
        $body = '';
        foreach ($messages as $message) $body .= $message->toSse();
        return (new Response())
            ->header('Content-Type', 'text/event-stream; charset=utf-8')
            ->header('Cache-Control', 'no-cache')
            ->header('Connection', 'keep-alive')
            ->header('X-Accel-Buffering', 'no')
            ->send($body . ($messages === [] ? ": heartbeat\n\n" : ''));
    }
}
