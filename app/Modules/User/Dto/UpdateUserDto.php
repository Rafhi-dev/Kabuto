<?php

declare(strict_types=1);

namespace App\Modules\User\Dto;

use Kabuto\Support\DTO;

final readonly class UpdateUserDto extends DTO
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?int $age = null,
        public ?string $status = null,
    ) {
    }

    protected static function rules(): array
    {
        return [
            'name' => 'nullable|string|min:2|max:100',
            'email' => 'nullable|email',
            'age' => 'nullable|int|min:0',
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
