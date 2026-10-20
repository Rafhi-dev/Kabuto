<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

interface RealtimeChannel
{
    public function publish(RealtimeMessage $message): void;
    /** @return list<RealtimeMessage> */
    public function messages(): array;
}
