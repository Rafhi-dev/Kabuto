<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Exceptions\HttpException;
use Kabuto\Http\ErrorResponse;
use Kabuto\Tests\TestCase;

final class ErrorResponseTest extends TestCase
{
    public function testProductionResponseRedactsHttpPayloadAndCarriesRequestId(): void
    {
        $response = ErrorResponse::fromThrowable(new HttpException(422, 'Invalid input', ['email' => ['Invalid']]), 'req_test');
        $data = json_decode($response->getBody(), true);
        self::assertSame('VALIDATION_FAILED', $data['error']['code']);
        self::assertSame('req_test', $data['error']['request_id']);
        self::assertFalse(isset($data['error']['details']));
        self::assertSame('req_test', $response->headers()['X-Request-ID']);
    }
}
