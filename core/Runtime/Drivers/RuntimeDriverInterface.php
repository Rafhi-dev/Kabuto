<?php

declare(strict_types=1);

namespace Kabuto\Runtime\Drivers;

use Kabuto\Runtime\WorkerServer;

interface RuntimeDriverInterface
{
    /**
     * Start the driver event loop on the listening server socket.
     *
     * @param resource $serverSocket
     */
    public function loop($serverSocket, WorkerServer $server): void;

    /**
     * Stop the event loop.
     */
    public function stop(): void;

    /**
     * Human-readable name of the driver.
     */
    public function name(): string;

    /**
     * Check whether this driver is supported in the current PHP environment.
     */
    public static function isSupported(): bool;
}
