<?php

declare(strict_types=1);

use Kabuto\Database\Schema;

/**
 * Kabuto Prisma-Style Schema Definition.
 *
 * Supports native Prisma field types & attributes:
 * - Int @id @default(autoincrement())
 * - String @unique, String? (nullable)
 * - DateTime @default(now())
 * - Post[] (hasMany)
 * - User @relation(fields: [author_id], references: [id]) (belongsTo)
 */
return Schema::define([
    'datasource' => [
        'provider' => env('DB_CONNECTION', 'sqlite'),
        'url' => env('DATABASE_URL', base_path('database/app.sqlite')),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'kabuto'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'schemas' => ['base', 'logs'],
    ],
    'models' => [
        'User' => [
            'table' => 'users',
            'schema' => 'base',
            'fields' => [
                'id'         => 'Int @id @default(autoincrement())',
                'name'       => 'String(100)',
                'email'      => 'String(150) @unique',
                'age'        => 'Int?',
                'status'     => 'Enum(active,inactive) @default(active)',
                'created_at' => 'DateTime @default(now())',
            ],
            'relations' => [
                'posts' => 'Post[]',
            ],
        ],
        'Post' => [
            'table' => 'posts',
            'schema' => 'base',
            'fields' => [
                'id'         => 'Int @id @default(autoincrement())',
                'author_id'  => 'Int @index',
                'title'      => 'String(200)',
                'body'       => 'Text?',
                'created_at' => 'DateTime @default(now())',
            ],
            'relations' => [
                'author' => 'User @relation(fields: [author_id], references: [id])',
            ],
        ],
        'AuditLog' => [
            'table' => 'events',
            'schema' => 'logs',
            'fields' => [
                'id'         => 'Int @id @default(autoincrement())',
                'action'     => 'String(100)',
                'meta'       => 'Json?',
                'created_at' => 'DateTime @default(now())',
            ],
        ],
    ],
]);
