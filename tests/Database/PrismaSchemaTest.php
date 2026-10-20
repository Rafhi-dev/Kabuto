<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\Schema;
use Kabuto\Tests\TestCase;

final class PrismaSchemaTest extends TestCase
{
    public function testPrismaStringAttributes(): void
    {
        $schema = Schema::define([
            'datasource' => [
                'provider' => 'sqlite',
                'url' => ':memory:',
            ],
            'models' => [
                'User' => [
                    'table' => 'users',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'email' => 'String(150) @unique',
                        'name' => 'String?',
                        'age' => 'Int?',
                        'is_active' => 'Boolean @default(true)',
                        'created_at' => 'DateTime @default(now())',
                    ],
                    'relations' => [
                        'posts' => 'Post[]',
                    ],
                ],
                'Post' => [
                    'table' => 'posts',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'author_id' => 'Int @index',
                        'title' => 'String(200)',
                    ],
                    'relations' => [
                        'author' => 'User @relation(fields: [author_id], references: [id])',
                    ],
                ],
            ],
        ]);

        $user = $schema->model('User');
        self::assertSame('increments', $user->field('id')->type);
        self::assertSame('string', $user->field('email')->type);
        self::assertSame(150, $user->field('email')->length);
        self::assertTrue($user->field('email')->has('unique'));
        self::assertTrue($user->field('name')->has('nullable'));
        self::assertTrue($user->field('age')->has('nullable'));
        self::assertSame('boolean', $user->field('is_active')->type);
        self::assertTrue($user->field('is_active')->hasDefault);
        self::assertSame(true, $user->field('is_active')->default);
        self::assertTrue($user->field('created_at')->has('useCurrent'));

        // Relations
        self::assertTrue($user->hasRelation('posts'));
        self::assertSame('hasMany', $user->relation('posts')->kind);
        self::assertSame('Post', $user->relation('posts')->model);
        self::assertSame('author_id', $user->relation('posts')->foreignKey);

        $post = $schema->model('Post');
        self::assertTrue($post->hasRelation('author'));
        self::assertSame('belongsTo', $post->relation('author')->kind);
        self::assertSame('User', $post->relation('author')->model);
        self::assertSame('author_id', $post->relation('author')->foreignKey);
    }

    public function testFluentModelBuilder(): void
    {
        $schema = Schema::define([
            'datasource' => [
                'provider' => 'sqlite',
                'url' => ':memory:',
            ],
            'models' => [
                Schema::model('Product')
                    ->map('shop_products')
                    ->id()
                    ->string('title', 120)->unique()
                    ->int('price')
                    ->boolean('in_stock')->default(true)
                    ->datetime('created_at')->defaultNow(),
            ],
        ]);

        $product = $schema->model('Product');
        self::assertSame('shop_products', $product->table);
        self::assertSame('increments', $product->field('id')->type);
        self::assertSame('string', $product->field('title')->type);
        self::assertSame(120, $product->field('title')->length);
        self::assertTrue($product->field('title')->has('unique'));
        self::assertSame('integer', $product->field('price')->type);
        self::assertSame('boolean', $product->field('in_stock')->type);
        self::assertSame(true, $product->field('in_stock')->default);
        self::assertTrue($product->field('created_at')->has('useCurrent'));
    }

    public function testFromPrismaDslString(): void
    {
        $prismaDsl = <<<'PRISMA'
datasource db {
  provider = "sqlite"
  url      = "database/app.sqlite"
}

model Customer {
  id        Int      @id @default(autoincrement())
  email     String   @unique
  name      String?
  orders    Order[]
  createdAt DateTime @default(now())

  @@map("customers")
}

model Order {
  id         Int      @id @default(autoincrement())
  customerId Int      @map("customer_id")
  customer   Customer @relation(fields: [customerId], references: [id])
  total      Int
  createdAt  DateTime @default(now())

  @@map("orders")
}
PRISMA;

        $schema = Schema::fromPrisma($prismaDsl);

        self::assertTrue($schema->hasModel('Customer'));
        self::assertTrue($schema->hasModel('Order'));

        $customer = $schema->model('Customer');
        self::assertSame('customers', $customer->table);
        self::assertSame('increments', $customer->field('id')->type);
        self::assertTrue($customer->field('email')->has('unique'));
        self::assertTrue($customer->field('name')->has('nullable'));
        self::assertTrue($customer->hasRelation('orders'));
        self::assertSame('hasMany', $customer->relation('orders')->kind);

        $order = $schema->model('Order');
        self::assertSame('orders', $order->table);
        self::assertTrue($order->hasRelation('customer'));
        self::assertSame('belongsTo', $order->relation('customer')->kind);
        self::assertSame('customerId', $order->relation('customer')->foreignKey);
    }

    public function testDatabaseUrlParsing(): void
    {
        $schema = Schema::define([
            'datasource' => [
                'provider' => 'mysql',
                'url' => 'mysql://appuser:secret123@db.internal:3307/production_db',
            ],
            'models' => [
                'Item' => [
                    'table' => 'items',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                    ],
                ],
            ],
        ]);

        $ds = $schema->datasource('db');
        self::assertSame('mysql', $ds->driver);
        self::assertSame('db.internal', $ds->options['host']);
        self::assertSame(3307, $ds->options['port']);
        self::assertSame('appuser', $ds->options['username']);
        self::assertSame('secret123', $ds->options['password']);
        self::assertSame('production_db', $ds->options['database']);
    }
}
