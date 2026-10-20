<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

final class ArrayRealtimeChannel implements RealtimeChannel
{
    /** @var list<RealtimeMessage> */
    private array $messages = [];
    public function publish(RealtimeMessage $message): void { $this->messages[] = $message; }
    public function messages(): array { return $this->messages; }
}
