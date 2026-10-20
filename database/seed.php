<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\User;
use Kabuto\Database\DB;

/**
 * Kabuto Database Seeder.
 *
 * Executed via: php kabuto db:seed
 * Uses Prisma-style ORM client for inserting initial/dummy data.
 */

// Example: Clean up existing data or insert demo records
if (class_exists(User::class)) {
    // Upsert or create demo admin user
    $user = User::upsert([
        'where' => ['email' => 'admin@kabuto.dev'],
        'update' => [
            'name' => 'Kabuto Admin',
            'status' => 'active',
        ],
        'create' => [
            'name' => 'Kabuto Admin',
            'email' => 'admin@kabuto.dev',
            'status' => 'active',
            'age' => 28,
        ],
    ]);

    if (class_exists(Post::class) && isset($user['id'])) {
        Post::create([
            'data' => [
                'author_id' => $user['id'],
                'title' => 'Welcome to Kabuto',
                'body' => 'A zero-dependency, NestJS-style micro framework for PHP with Prisma-like ORM.',
            ],
        ]);
    }
}
