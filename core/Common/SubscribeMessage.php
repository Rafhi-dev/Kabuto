<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Binds a gateway method to a client operation name. The handler receives the
 * decoded payload and the client context, and may return an array/response that
 * is written back to the client.
 *
 *   #[SubscribeMessage('chat.send')]
 *   public function send(array $payload, array $client): array { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class SubscribeMessage
{
    public function __construct(public readonly string $event)
    {
    }
}
