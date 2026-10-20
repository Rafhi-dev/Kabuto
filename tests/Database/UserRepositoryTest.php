<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use App\Modules\User\UserRepository;
use Kabuto\Application;
use Kabuto\Database\DB;

final class UserRepositoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        $app->container()->instance(DB::class, $this->db);
    }

    public function testUserRepositoryCrudOperationsWithDatabase(): void
    {
        $repo = new UserRepository();

        // 1. Initial table is empty
        self::assertSame([], $repo->all());

        // 2. Create user
        $created = $repo->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@navy.mil',
            'age' => 85,
            'status' => 'active',
        ]);

        self::assertSame('Grace Hopper', $created['name']);
        self::assertSame('grace@navy.mil', $created['email']);
        self::assertSame(85, $created['age']);
        self::assertSame('active', $created['status']);
        self::assertTrue(isset($created['id']));
        $id = (int) $created['id'];

        // 3. Find user
        $found = $repo->find($id);
        self::assertTrue($found !== null);
        self::assertSame('Grace Hopper', $found['name']);
        self::assertSame('grace@navy.mil', $found['email']);

        // 4. Update user
        $updated = $repo->update($id, [
            'status' => 'inactive',
        ]);
        self::assertTrue($updated !== null);
        self::assertSame('inactive', $updated['status']);
        self::assertSame('Grace Hopper', $updated['name']);

        // 5. All users
        $all = $repo->all();
        self::assertCount(1, $all);
        self::assertSame('Grace Hopper', $all[0]['name']);

        // 6. Delete user
        $deleted = $repo->delete($id);
        self::assertTrue($deleted);

        // 7. Verify deletion
        self::assertNull($repo->find($id));
        self::assertSame([], $repo->all());
    }
}
