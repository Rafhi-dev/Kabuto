<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

use Kabuto\Common\SubscribeMessage;
use Kabuto\Common\WebSocketGateway;
use Kabuto\Container\Container;
use ReflectionClass;
use ReflectionMethod;

/**
 * Registry of #[WebSocketGateway] classes and their #[SubscribeMessage]
 * handlers. The WebSocket server dispatches unknown operations to matching
 * handlers before rejecting them.
 */
final class GatewayRegistry
{
    /** @var array<string,array{gateway:class-string,method:string}> */
    private array $handlers = [];

    /** @var array<class-string,WebSocketGateway> */
    private array $gateways = [];

    /** @var array<class-string,object> */
    private array $instances = [];

    private ?WebSocketServer $server = null;

    public function __construct(private readonly ?Container $container = null)
    {
    }

    /**
     * @param class-string $gateway
     */
    public function register(string $gateway): self
    {
        if (!class_exists($gateway)) {
            throw new \LogicException(sprintf('Gateway [%s] does not exist.', $gateway));
        }

        $reflection = new ReflectionClass($gateway);
        $attributes = $reflection->getAttributes(WebSocketGateway::class);

        if ($attributes === []) {
            throw new \LogicException(sprintf('Class [%s] is not a gateway. Add #[WebSocketGateway] above the class declaration.', $gateway));
        }

        /** @var WebSocketGateway $definition */
        $definition = $attributes[0]->newInstance();
        $this->gateways[$gateway] = $definition;

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            foreach ($method->getAttributes(SubscribeMessage::class) as $attribute) {
                /** @var SubscribeMessage $message */
                $message = $attribute->newInstance();
                $event = $message->event;

                if ($event === '') {
                    throw new \LogicException(sprintf('Gateway [%s::%s] has an empty #[SubscribeMessage] name.', $gateway, $method->getName()));
                }
                if (isset($this->handlers[$event])) {
                    $existing = $this->handlers[$event];
                    throw new \LogicException(sprintf(
                        'Duplicate gateway handler for event [%s]: [%s::%s] and [%s::%s].',
                        $event,
                        $existing['gateway'],
                        $existing['method'],
                        $gateway,
                        $method->getName(),
                    ));
                }

                $this->handlers[$event] = ['gateway' => $gateway, 'method' => $method->getName()];
            }
        }

        return $this;
    }

    public function has(string $event): bool
    {
        return isset($this->handlers[$event]);
    }

    /** @return list<string> */
    public function events(): array
    {
        return array_keys($this->handlers);
    }

    /** @return list<class-string> */
    public function gateways(): array
    {
        return array_keys($this->gateways);
    }

    public function definition(string $gateway): ?WebSocketGateway
    {
        return $this->gateways[$gateway] ?? null;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $client */
    public function dispatch(string $event, array $payload, array $client): mixed
    {
        $handler = $this->handlers[$event] ?? null;
        if ($handler === null) {
            throw new \RuntimeException(sprintf('No gateway handler registered for event [%s].', $event));
        }

        $instance = $this->instance($handler['gateway']);

        return $instance->{$handler['method']}($payload, $client);
    }

    /** Bind the running server and inject it into server-aware gateways. */
    public function attachServer(WebSocketServer $server): void
    {
        $this->server = $server;

        foreach (array_keys($this->gateways) as $gateway) {
            $instance = $this->instance($gateway);
            if ($instance instanceof GatewayServerAware) {
                $instance->setWebSocketServer($server);
            }
        }
    }

    public function server(): ?WebSocketServer
    {
        return $this->server;
    }

    private function instance(string $gateway): object
    {
        if (isset($this->instances[$gateway])) {
            return $this->instances[$gateway];
        }

        $instance = $this->container !== null ? $this->container->get($gateway) : new $gateway();
        if (!is_object($instance)) {
            throw new \LogicException(sprintf('Gateway [%s] did not resolve to an object.', $gateway));
        }

        return $this->instances[$gateway] = $instance;
    }
}
