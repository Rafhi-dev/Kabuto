<?php

declare(strict_types=1);

namespace App\Modules\User;

use App\Modules\User\Dto\CreateUserDto;
use App\Modules\User\Dto\UpdateUserDto;
use Kabuto\Common\Injectable;
use Kabuto\Exceptions\NotFoundException;

#[Injectable]
final class UserService
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function findAll(): array
    {
        return $this->userRepository->all();
    }

    public function findOne(int $id): array
    {
        $user = $this->userRepository->find($id);

        if ($user === null) {
            throw new NotFoundException("User with ID {$id} not found");
        }

        return $user;
    }

    public function create(CreateUserDto $dto): array
    {
        return $this->userRepository->create($dto->toArray());
    }

    public function update(int $id, UpdateUserDto $dto): array
    {
        $user = $this->findOne($id);

        $payload = $dto->toArray(filterNull: true);
        if ($payload === []) {
            return $user;
        }

        return $this->userRepository->update($id, $payload) ?? $user;
    }

    public function remove(int $id): bool
    {
        $this->findOne($id);

        return $this->userRepository->delete($id);
    }
}
