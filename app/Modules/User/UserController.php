<?php

declare(strict_types=1);

namespace App\Modules\User;

use App\Modules\User\Dto\CreateUserDto;
use App\Modules\User\Dto\UpdateUserDto;
use Kabuto\Common\Body;
use Kabuto\Common\Controller;
use Kabuto\Common\Delete;
use Kabuto\Common\Get;
use Kabuto\Common\HttpCode;
use Kabuto\Common\HttpStatus;
use Kabuto\Common\Param;
use Kabuto\Common\Post;
use Kabuto\Common\Put;

#[Controller('users')]
final class UserController
{
    public function __construct(
        private readonly UserService $userService,
    ) {
    }

    #[Get]
    public function findAll(): array
    {
        return ['data' => $this->userService->findAll()];
    }

    #[Get(':id')]
    public function findOne(#[Param('id')] int $id): array
    {
        return ['data' => $this->userService->findOne($id)];
    }

    #[Post]
    #[HttpCode(HttpStatus::CREATED)]
    public function create(#[Body] CreateUserDto $dto): array
    {
        return ['data' => $this->userService->create($dto)];
    }

    #[Put(':id')]
    public function update(#[Param('id')] int $id, #[Body] UpdateUserDto $dto): array
    {
        return ['data' => $this->userService->update($id, $dto)];
    }

    #[Delete(':id')]
    public function remove(#[Param('id')] int $id): array
    {
        $this->userService->remove($id);

        return ['message' => 'User deleted successfully'];
    }
}
