<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\Console;
use Kabuto\Database\ConnectionManager;
use Kabuto\Database\SchemaLoader;
use Kabuto\Queue\DatabaseQueue;
use Kabuto\Queue\RedisQueue;
use Kabuto\Redis\RedisConnectionFactory;

abstract class QueueCommand extends Command
{
    protected DatabaseQueue|RedisQueue|null $queue = null;

    public function __construct(Console $console, array $arguments = [])
    {
        parent::__construct($console, $arguments);
    }

    protected function queue(): DatabaseQueue|RedisQueue
    {
        if ($this->queue !== null) return $this->queue;
        if (strtolower((string) config('queue.driver', 'database')) === 'redis') {
            $factory = new RedisConnectionFactory((array) config('redis', []));
            return $this->queue = new RedisQueue($factory->make(), $this->option('queue', 'default') ?? 'default', (string) config('redis.prefix', 'kabuto:') . 'queue:');
        }
        $schema = SchemaLoader::load($this->console->basePath(), (array) config('database', []));
        $datasource = $this->option('datasource') ?? $schema->defaultDatasource ?? 'default';
        return $this->queue = new DatabaseQueue((new ConnectionManager($schema))->connection($datasource), $this->option('queue', 'default') ?? 'default');
    }
}
