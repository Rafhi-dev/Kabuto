<?php

declare(strict_types=1);

namespace Kabuto\Tests\Support;

use Kabuto\Exceptions\ValidationException;
use Kabuto\Support\DTO;
use Kabuto\Tests\TestCase;

final class DTOTest extends TestCase
{
    public function testHydratesValidData(): void
    {
        $dto = UserInput::fromArray(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => '36']);

        self::assertSame('Ada', $dto->name);
        self::assertSame('ada@example.com', $dto->email);
        self::assertSame(36, $dto->age);
    }

    public function testMissingRequiredThrows422(): void
    {
        try {
            UserInput::fromArray(['email' => 'ada@example.com']);
            self::assertTrue(false, 'Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatus());
            self::assertArrayHasKey('name', $e->errors());
        }
    }

    public function testInvalidEmailThrows(): void
    {
        self::assertThrows(
            ValidationException::class,
            static fn () => UserInput::fromArray(['name' => 'Ada', 'email' => 'not-an-email']),
        );
    }

    public function testMinMaxRules(): void
    {
        self::assertThrows(
            ValidationException::class,
            static fn () => UserInput::fromArray(['name' => 'A', 'email' => 'a@b.co']),
        );
    }

    public function testNullableFieldMayBeMissing(): void
    {
        $dto = UserInput::fromArray(['name' => 'Ada', 'email' => 'ada@example.com']);

        self::assertNull($dto->age);
    }

    public function testNullableFieldIsValidatedWhenPresent(): void
    {
        self::assertThrows(
            ValidationException::class,
            static fn () => UserInput::fromArray(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => 'old']),
        );
    }

    public function testErrorPayloadShape(): void
    {
        try {
            UserInput::fromArray([]);
            self::assertTrue(false, 'Expected ValidationException.');
        } catch (ValidationException $e) {
            $payload = $e->getPayload();
            self::assertArrayHasKey('error', $payload);
            self::assertArrayHasKey('errors', $payload);
            self::assertArrayHasKey('name', $payload['errors']);
            self::assertArrayHasKey('email', $payload['errors']);
        }
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $dto = UserInput::fromArray(['name' => 'Ada', 'email' => 'ada@example.com', 'extra' => 'x']);

        self::assertSame('Ada', $dto->name);
    }

    public function testToArrayIncludesAllPropertiesByDefault(): void
    {
        $dto = UserInput::fromArray(['name' => 'Ada', 'email' => 'ada@example.com']);
        $array = $dto->toArray();

        self::assertSame(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => null], $array);
    }

    public function testToArrayFiltersNullWhenRequested(): void
    {
        $dto = UserInput::fromArray(['name' => 'Ada', 'email' => 'ada@example.com']);
        $array = $dto->toArray(filterNull: true);

        self::assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $array);
    }

    public function testNumericMinMaxWithNumericString(): void
    {
        $dto = NumericInput::fromArray(['quantity' => '20', 'price' => 15.5, 'tags' => ['php', 'kabuto']]);
        self::assertSame(20, $dto->quantity);

        self::assertThrows(
            ValidationException::class,
            static fn () => NumericInput::fromArray(['quantity' => '5', 'price' => 15.5, 'tags' => ['php', 'kabuto']]),
        );

        self::assertThrows(
            ValidationException::class,
            static fn () => NumericInput::fromArray(['quantity' => '20', 'price' => 15.5, 'tags' => ['php']]),
        );
    }
}

final readonly class NumericInput extends DTO
{
    public function __construct(
        public int $quantity,
        public float $price,
        public array $tags = [],
    ) {
    }

    protected static function rules(): array
    {
        return [
            'quantity' => 'required|int|min:10|max:100',
            'price' => 'required|numeric|min:1',
            'tags' => 'required|array|min:2',
        ];
    }
}

final readonly class UserInput extends DTO
{
    public function __construct(
        public string $name,
        public string $email,
        public ?int $age = null,
    ) {
    }

    protected static function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:50',
            'email' => 'required|email',
            'age' => 'nullable|int',
        ];
    }
}
