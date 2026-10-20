<?php

declare(strict_types=1);

namespace App;

use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\Param;
use Kabuto\Common\Query;

#[Controller('')]
final class AppController
{
    #[Get('')]
    public function index(): array
    {
        return [
            'name' => config('app.name'),
            'message' => 'NestJS-style micro framework for PHP',
        ];
    }

    #[Get('hello/:name')]
    public function hello(#[Param('name')] string $name): array
    {
        return ['hello' => $name];
    }

    #[Get('search')]
    public function search(#[Query('q')] string $term = ''): array
    {
        return ['q' => $term];
    }
}
