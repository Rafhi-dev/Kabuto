<?php

declare(strict_types=1);

namespace Kabuto\Tests\Container;

use Kabuto\Container\Container;
use Kabuto\Container\ContainerException;
use Kabuto\Tests\TestCase;

final class ContainerTest extends TestCase
{
    public function testAutowiresConcreteClass(): void
    {
        $container = new Container();

        self::assertInstanceOf(ServiceWithDependency::class, $container->get(ServiceWithDependency::class));
    }

    public function testAutowiresNestedDependencies(): void
    {
        $container = new Container();
        $service = $container->get(ServiceWithDependency::class);

        self::assertInstanceOf(LeafDependency::class, $service->leaf);
    }

    public function testUnregisteredResolvesFreshInstances(): void
    {
        $container = new Container();

        self::assertFalse($container->get(LeafDependency::class) === $container->get(LeafDependency::class));
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton(LeafDependency::class);

        self::assertTrue($container->get(LeafDependency::class) === $container->get(LeafDependency::class));
    }

    public function testBindIsTransient(): void
    {
        $container = new Container();
        $container->bind(LeafDependency::class);

        self::assertFalse($container->get(LeafDependency::class) === $container->get(LeafDependency::class));
    }

    public function testBindInterfaceToImplementation(): void
    {
        $container = new Container();
        $container->bind(GreeterInterface::class, FriendlyGreeter::class);

        self::assertInstanceOf(FriendlyGreeter::class, $container->get(GreeterInterface::class));
    }

    public function testBindFactory(): void
    {
        $container = new Container();
        $container->singleton('config.value', static fn () => 42);

        self::assertSame(42, $container->get('config.value'));
    }

    public function testInstanceRegistersObject(): void
    {
        $container = new Container();
        $leaf = new LeafDependency();
        $container->instance(LeafDependency::class, $leaf);

        self::assertTrue($leaf === $container->get(LeafDependency::class));
    }

    public function testUsesDefaultParameterValue(): void
    {
        $container = new Container();

        self::assertSame('default', $container->get(ServiceWithDefault::class)->name);
    }

    public function testThrowsForUnknownId(): void
    {
        $container = new Container();

        self::assertThrows(ContainerException::class, static fn () => $container->get('missing.service'));
    }

    public function testThrowsForUnresolvableScalar(): void
    {
        $container = new Container();

        self::assertThrows(ContainerException::class, static fn () => $container->get(ServiceWithScalar::class));
    }

    public function testHas(): void
    {
        $container = new Container();

        self::assertTrue($container->has(LeafDependency::class));
        self::assertFalse($container->has('missing.service'));
        self::assertFalse($container->has(ServiceWithScalar::class));
    }

    public function testForgetInstance(): void
    {
        $container = new Container();
        $leaf = new LeafDependency();
        $container->instance(LeafDependency::class, $leaf);

        self::assertTrue($container->bound(LeafDependency::class));
        $container->forgetInstance(LeafDependency::class);
        self::assertFalse($container->bound(LeafDependency::class));
    }

    public function testDiagnoseFindsCircularDependency(): void
    {
        $container = new Container();
        $errors = $container->diagnose([CircularA::class]);
        self::assertCount(1, $errors);
        self::assertStringContains('Circular dependency detected', $errors[0]);
    }
}

final class LeafDependency
{
}

final class ServiceWithDependency
{
    public function __construct(public readonly LeafDependency $leaf)
    {
    }
}

final class ServiceWithDefault
{
    public function __construct(public readonly string $name = 'default')
    {
    }
}

final class ServiceWithScalar
{
    public function __construct(public readonly string $name)
    {
    }
}

interface GreeterInterface
{
}

final class FriendlyGreeter implements GreeterInterface
{
}

final class CircularA
{
    public function __construct(CircularB $dependency) {}
}

final class CircularB
{
    public function __construct(CircularA $dependency) {}
}
