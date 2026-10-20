<?php

declare(strict_types=1);

namespace Kabuto\Tests\OpenApi;

use Kabuto\Application;
use Kabuto\Common\Auth;
use Kabuto\Common\Body;
use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\HttpCode;
use Kabuto\Common\Param;
use Kabuto\Common\Post;
use Kabuto\Common\Query;
use Kabuto\Common\UploadedFile as UploadAttribute;
use Kabuto\Http\UploadedFile;
use Kabuto\OpenApi\OpenApiGenerator;
use Kabuto\Support\DTO;
use Kabuto\Tests\TestCase;

final class OpenApiGeneratorTest extends TestCase
{
    public function testGeneratesPathsParametersSchemasAndSecurity(): void
    {
        $app = new Application();
        $app->router()->get('/users/:id', [OpenApiFixtureController::class, 'show']);
        $app->router()->post('/users', [OpenApiFixtureController::class, 'create']);
        $app->router()->post('/avatars', [OpenApiFixtureController::class, 'avatar']);
        $document = (new OpenApiGenerator($app->router()))->generate('Test API', '2.0.0');

        self::assertSame('3.0.3', $document['openapi']);
        self::assertSame('Test API', $document['info']['title']);
        self::assertArrayHasKey('/users/{id}', $document['paths']);
        self::assertSame('path', $document['paths']['/users/{id}']['get']['parameters'][0]['in']);
        self::assertSame('query', $document['paths']['/users/{id}']['get']['parameters'][1]['in']);
        self::assertArrayHasKey('CreateUserInput', $document['components']['schemas']);
        self::assertArrayHasKey('security', $document['paths']['/users/{id}']['get']);
        self::assertArrayHasKey('requestBody', $document['paths']['/avatars']['post']);
        self::assertSame(201, array_key_first($document['paths']['/users']['post']['responses']));
    }
}

#[Controller('users')]
final class OpenApiFixtureController
{
    #[Auth]
    #[Get(':id')]
    public function show(#[Param('id')] int $id, #[Query('include')] ?string $include = null): array { return []; }

    #[Post()]
    #[HttpCode(201)]
    public function create(#[Body] CreateUserInput $input): array { return []; }

    #[Post('avatars')]
    public function avatar(#[UploadAttribute('file', mimes: ['image/png'])] UploadedFile $file): array { return []; }
}

final readonly class CreateUserInput extends DTO
{
    public function __construct(public string $name, public ?int $age = null) {}
}
