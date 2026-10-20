<?php

declare(strict_types=1);

namespace App\Modules\User;

use Kabuto\Common\Injectable;

#[Injectable]
class UserRepository
{

    public function all(): array
    {
        return db()->user->findMany(['orderBy' => ['id' => 'desc']]);
    }

    public function find(int $id): ?array
    {
        return db()->user->findUnique(['where' => ['id' => $id]]);
    }

    public function create(array $data): array
    {
        return db()->user->create(['data' => $data]);
    }

    public function update(int $id, array $data): ?array
    {
        return db()->user->update([
            'where' => ['id' => $id],
            'data' => $data,
        ]);
    }

    public function delete(int $id): bool
    {
        return (bool) db()->user->delete(['where' => ['id' => $id]]);
    }
}
