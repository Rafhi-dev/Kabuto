<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\Gate;
use Kabuto\Auth\PolicyRegistry;
use Kabuto\Tests\TestCase;

final class PolicyDiscoveryTest extends TestCase
{
    protected function tearDown(): void { Gate::flush(); }

    public function testRegistryDiscoversNestedPoliciesWithConfiguredNamespaces(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kabuto-policies-' . bin2hex(random_bytes(4));
        mkdir($root . '/Admin', 0777, true);
        eval('namespace Demo\\Models; class Report {}');
        eval('namespace Demo\\Policies\\Admin; class ReportPolicy { public function view(mixed $user, object $model): bool { return true; } }');
        file_put_contents($root . '/Admin/ReportPolicy.php', '<?php');

        $registry = new PolicyRegistry();
        self::assertSame(1, $registry->discover($root, 'Demo\\Policies', 'Demo\\Models'));
        self::assertTrue($registry->for(new \Demo\Models\Report()) instanceof \Demo\Policies\Admin\ReportPolicy);
        Gate::registerPolicies($registry);
        Gate::forUser(['id' => 1])->authorizeModel('view', new \Demo\Models\Report());
        @unlink($root . '/Admin/ReportPolicy.php');
        @rmdir($root . '/Admin');
        @rmdir($root);
    }
}
