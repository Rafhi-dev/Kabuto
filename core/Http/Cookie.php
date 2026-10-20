<?php

declare(strict_types=1);

namespace Kabuto\Http;

use Kabuto\Support\Crypto;
use Stringable;

final class Cookie implements Stringable
{
    private int $expires = 0;
    private string $path = '/';
    private ?string $domain = null;
    private bool $secure = false;
    private bool $httpOnly = true;
    private string $sameSite = 'Lax';
    private bool $encrypt = false;

    public function __construct(
        private readonly string $name,
        private string $value = '',
        int $minutes = 0,
    ) {
        if ($minutes > 0) {
            $this->expires = time() + ($minutes * 60);
        }
    }

    public static function make(string $name, string $value = '', int $minutes = 0): self
    {
        return new self($name, $value, $minutes);
    }

    /**
     * Create a persistent cookie that lasts for 5 years.
     */
    public static function forever(string $name, string $value): self
    {
        return (new self($name, $value))->minutes(5 * 365 * 24 * 60);
    }

    /**
     * Create an expired cookie to remove it from the client browser.
     */
    public static function forget(string $name, string $path = '/', ?string $domain = null): self
    {
        $cookie = new self($name, '', 0);
        $cookie->expires = 1; // 1970-01-01 00:00:01
        $cookie->path = $path;
        $cookie->domain = $domain;

        return $cookie;
    }

    public function path(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function domain(?string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function secure(bool $secure = true): self
    {
        $this->secure = $secure;
        return $this;
    }

    public function httpOnly(bool $httpOnly = true): self
    {
        $this->httpOnly = $httpOnly;
        return $this;
    }

    public function sameSite(string $sameSite): self
    {
        $this->sameSite = ucfirst(strtolower($sameSite));
        return $this;
    }

    public function expires(int $timestamp): self
    {
        $this->expires = $timestamp;
        return $this;
    }

    public function minutes(int $minutes): self
    {
        $this->expires = time() + ($minutes * 60);
        return $this;
    }

    public function encrypt(bool $encrypt = true): self
    {
        $this->encrypt = $encrypt;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getExpires(): int
    {
        return $this->expires;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    public function getSameSite(): string
    {
        return $this->sameSite;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypt;
    }

    public function toString(): string
    {
        $val = $this->encrypt && $this->value !== ''
            ? Crypto::encrypt($this->value)
            : $this->value;

        $str = rawurlencode($this->name) . '=' . rawurlencode($val);

        if ($this->expires > 0) {
            $maxAge = max(0, $this->expires - time());
            $str .= '; Expires=' . gmdate('D, d M Y H:i:s T', $this->expires) . '; Max-Age=' . $maxAge;
        }

        if ($this->path !== '') {
            $str .= '; Path=' . $this->path;
        }

        if ($this->domain !== null && $this->domain !== '') {
            $str .= '; Domain=' . $this->domain;
        }

        if ($this->secure) {
            $str .= '; Secure';
        }

        if ($this->httpOnly) {
            $str .= '; HttpOnly';
        }

        if ($this->sameSite !== '') {
            $str .= '; SameSite=' . $this->sameSite;
        }

        return $str;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
