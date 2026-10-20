<?php

declare(strict_types=1);

namespace App\Modules\User\Dto;

use Kabuto\Support\DTO;

final readonly class CreateUserDto extends DTO
{
    public function __construct(
        public string $name,
        public string $email,
        public ?int $age = null,
        public string $status = 'active',
    ) {
    }

    protected static function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email',
            'age' => 'nullable|int|min:0',
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
