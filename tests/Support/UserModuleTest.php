<?php

declare(strict_types=1);

namespace Kabuto\Tests\Support;

use App\Modules\User\Dto\CreateUserDto;
use App\Modules\User\Dto\UpdateUserDto;
use App\Modules\User\UserController;
use App\Modules\User\UserRepository;
use App\Modules\User\UserService;
use Kabuto\Exceptions\NotFoundException;
use Kabuto\Exceptions\ValidationException;
use Kabuto\Tests\TestCase;

final class UserModuleTest extends TestCase
{
    public function testCreateUserDtoValidatesAndConvertsToArray(): void
    {
        $dto = CreateUserDto::fromArray([
            'name' => 'Alice Doe',
            'email' => 'alice@example.com',
            'age' => 25,
            'status' => 'active',
        ]);

        self::assertSame('Alice Doe', $dto->name);
        self::assertSame('alice@example.com', $dto->email);
        self::assertSame(25, $dto->age);
        self::assertSame('active', $dto->status);

        $array = $dto->toArray();
        self::assertSame([
            'name' => 'Alice Doe',
            'email' => 'alice@example.com',
            'age' => 25,
            'status' => 'active',
        ], $array);
    }

    public function testCreateUserDtoRequiresNameAndEmail(): void
    {
        self::assertThrows(
            ValidationException::class,
            static fn () => CreateUserDto::fromArray(['name' => 'Alice']),
        );
    }

    public function testUpdateUserDtoFiltersNullProperties(): void
    {
        $dto = UpdateUserDto::fromArray([
            'name' => 'Alice Updated',
        ]);

        self::assertSame('Alice Updated', $dto->name);
        self::assertNull($dto->email);
        self::assertNull($dto->age);
        self::assertNull($dto->status);

        // toArray(filterNull: true) should only include 'name'
        self::assertSame(['name' => 'Alice Updated'], $dto->toArray(filterNull: true));
        // toArray() without arguments includes nulls
        self::assertSame(['name' => 'Alice Updated', 'email' => null, 'age' => null, 'status' => null], $dto->toArray());
    }

    public function testUserServiceThrowsNotFoundException(): void
    {
        $fakeRepo = new class extends UserRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        };

        $service = new UserService($fakeRepo);

        self::assertThrows(
            NotFoundException::class,
            static fn () => $service->findOne(999),
        );
    }

    public function testUserServiceCrudFlow(): void
    {
        $fakeRepo = new class extends UserRepository {
            /** @var array<int,array<string,mixed>> */
            public array $storage = [
                1 => ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com', 'age' => 30, 'status' => 'active'],
            ];

            public function all(): array
            {
                return array_values($this->storage);
            }

            public function find(int $id): ?array
            {
                return $this->storage[$id] ?? null;
            }

            public function create(array $data): array
            {
                $id = count($this->storage) + 1;
                $row = ['id' => $id] + $data;
                $this->storage[$id] = $row;
                return $row;
            }

            public function update(int $id, array $data): ?array
            {
                if (!isset($this->storage[$id])) {
                    return null;
                }
                $this->storage[$id] = array_merge($this->storage[$id], $data);
                return $this->storage[$id];
            }

            public function delete(int $id): bool
            {
                if (!isset($this->storage[$id])) {
                    return false;
                }
                unset($this->storage[$id]);
                return true;
            }
        };

        $service = new UserService($fakeRepo);
        $controller = new UserController($service);

        // 1. Find all
        $all = $controller->findAll();
        self::assertSame(1, count($all['data']));

        // 2. Find one
        $one = $controller->findOne(1);
        self::assertSame('Alice', $one['data']['name']);

        // 3. Create
        $created = $controller->create(CreateUserDto::fromArray([
            'name' => 'Bob',
            'email' => 'bob@test.com',
        ]));
        self::assertSame(2, $created['data']['id']);
        self::assertSame('Bob', $created['data']['name']);

        // 4. Update
        $updated = $controller->update(2, UpdateUserDto::fromArray([
            'status' => 'inactive',
        ]));
        self::assertSame('inactive', $updated['data']['status']);
        self::assertSame('Bob', $updated['data']['name']);

        // 5. Remove
        $removed = $controller->remove(2);
        self::assertSame(['message' => 'User deleted successfully'], $removed);
        self::assertSame(1, count($service->findAll()));
    }

    public function testUserServiceUpdateWithEmptyPayloadReturnsUserUnchanged(): void
    {
        $fakeRepo = new class extends UserRepository {
            public bool $updateCalled = false;

            public function find(int $id): ?array
            {
                return ['id' => $id, 'name' => 'Alice', 'email' => 'alice@test.com', 'age' => 25, 'status' => 'active'];
            }

            public function update(int $id, array $data): ?array
            {
                $this->updateCalled = true;
                return ['id' => $id] + $data;
            }
        };

        $service = new UserService($fakeRepo);
        $result = $service->update(1, UpdateUserDto::fromArray([]));

        self::assertFalse($fakeRepo->updateCalled);
        self::assertSame('Alice', $result['name']);
        self::assertSame(25, $result['age']);
    }

    public function testUserServiceUpdateWhenRepoReturnsNullFallsBackToUser(): void
    {
        $fakeRepo = new class extends UserRepository {
            public function find(int $id): ?array
            {
                return ['id' => $id, 'name' => 'Alice', 'email' => 'alice@test.com', 'age' => 25, 'status' => 'active'];
            }

            public function update(int $id, array $data): ?array
            {
                return null;
            }
        };

        $service = new UserService($fakeRepo);
        $result = $service->update(1, UpdateUserDto::fromArray(['status' => 'active']));

        self::assertSame('Alice', $result['name']);
        self::assertSame('active', $result['status']);
    }
}
