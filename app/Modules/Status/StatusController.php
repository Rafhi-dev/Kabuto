<?php

declare(strict_types=1);

namespace App\Modules\Status;

use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\Param;

#[Controller('api/meta')]
final class StatusController
{
    #[Get('ping')]
    public function ping(): array
    {
        return ['pong' => true, 'module' => 'Status'];
    }

    #[Get('echo/:word')]
    public function echo(#[Param('word')] string $word): array
    {
        return ['word' => $word];
    }
}
