<?php

declare(strict_types=1);

namespace Kabuto\Events;

interface Listener
{
    public function handle(Event $event): void;
}
