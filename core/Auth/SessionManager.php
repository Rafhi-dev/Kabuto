<?php

declare(strict_types=1);

namespace Kabuto\Auth;

use Kabuto\Http\Cookie;
use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class SessionManager
{
    private ?string $id = null;
    /** @var array<string,mixed> */
    private array $data = [];
    private bool $started = false;

    public function __construct(
        private readonly SessionStore $store,
        private readonly string $cookieName = 'kabuto_session',
        private readonly int $lifetime = 7200,
        private readonly bool $secure = false,
    ) {}

    public function start(Request $request): self
    {
        if ($this->started) return $this;
        $candidate = $request->cookie($this->cookieName);
        if (is_string($candidate) && preg_match('/^[a-f0-9]{64}$/', $candidate) === 1) {
            $this->id = $candidate;
            $this->data = $this->store->read($candidate) ?? [];
        }
        if ($this->id === null) {
            $this->id = bin2hex(random_bytes(32));
        }
        $this->started = true;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }

    public function put(string $key, mixed $value): self { $this->data[$key] = $value; return $this; }

    public function user(): mixed { return $this->get('_user'); }

    public function login(mixed $user): self { $this->regenerate(); return $this->put('_user', $user); }

    public function logout(): void
    {
        if ($this->id !== null) $this->store->delete($this->id);
        $this->data = [];
        $this->id = bin2hex(random_bytes(32));
    }

    public function regenerate(): self
    {
        $old = $this->id;
        $this->id = bin2hex(random_bytes(32));
        if ($old !== null) $this->store->delete($old);
        return $this;
    }

    public function commit(Response $response): Response
    {
        if (!$this->started || $this->id === null) return $response;
        $this->store->write($this->id, $this->data, time() + $this->lifetime);
        return $response->withCookie(Cookie::make($this->cookieName, $this->id, intdiv($this->lifetime, 60))
            ->secure($this->secure)->httpOnly(true)->sameSite('Lax'));
    }
}
