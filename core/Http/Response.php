<?php

declare(strict_types=1);

namespace Kabuto\Http;

final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var array<string,Cookie> */
    private array $cookies = [];

    private int $status = 200;

    private string $body = '';

    public function status(int $code): self
    {
        $this->status = $code;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * @param array{
     *     expires?: int,
     *     path?: string,
     *     domain?: ?string,
     *     secure?: bool,
     *     httponly?: bool,
     *     samesite?: string
     * } $options
     */
    /**
     * Set a cookie on the response.
     *
     * @param Cookie|string $name
     * @param string $value
     * @param array{
     *     expires?: int,
     *     path?: string,
     *     domain?: ?string,
     *     secure?: bool,
     *     httponly?: bool,
     *     samesite?: string,
     *     encrypt?: bool
     * } $options
     */
    public function cookie(Cookie|string $name, string $value = '', array $options = []): self
    {
        return $this->withCookie($name, $value, $options);
    }

    /**
     * Add a Cookie instance or create one with fluent options.
     *
     * @param Cookie|string $cookie
     * @param string $value
     * @param array{
     *     expires?: int,
     *     path?: string,
     *     domain?: ?string,
     *     secure?: bool,
     *     httponly?: bool,
     *     samesite?: string,
     *     encrypt?: bool
     * } $options
     */
    public function withCookie(Cookie|string $cookie, string $value = '', array $options = []): self
    {
        if ($cookie instanceof Cookie) {
            $this->cookies[$cookie->getName()] = $cookie;
            return $this;
        }

        $c = new Cookie($cookie, $value);

        if (isset($options['expires'])) {
            $c->expires($options['expires']);
        }
        if (isset($options['path'])) {
            $c->path($options['path']);
        }
        if (isset($options['domain'])) {
            $c->domain($options['domain']);
        }
        if (isset($options['secure'])) {
            $c->secure((bool) $options['secure']);
        }
        if (isset($options['httponly'])) {
            $c->httpOnly((bool) $options['httponly']);
        }
        if (isset($options['samesite'])) {
            $c->sameSite($options['samesite']);
        }
        if (!empty($options['encrypt'])) {
            $c->encrypt(true);
        }

        $this->cookies[$cookie] = $c;

        return $this;
    }

    /**
     * Expire a cookie by name.
     */
    public function withoutCookie(string $name, string $path = '/', ?string $domain = null): self
    {
        $this->cookies[$name] = Cookie::forget($name, $path, $domain);
        return $this;
    }

    /**
     * @return array<string,Cookie>
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return list<string>
     */
    public function cookieHeaders(): array
    {
        return array_values(array_map(
            static fn (Cookie $c): string => $c->toString(),
            $this->cookies,
        ));
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        $headers = $this->headers;

        // If cookies exist, set the primary/last cookie string in headers for compatibility
        $cookieHeaders = $this->cookieHeaders();
        if ($cookieHeaders !== []) {
            $headers['Set-Cookie'] = end($cookieHeaders);
        }

        return $headers;
    }

    public function send(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function json(mixed $data, ?int $status = null): self
    {
        if ($status !== null) {
            $this->status = $status;
        }
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode response as JSON: ' . json_last_error_msg());
        }
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        $this->body = $encoded;
        return $this;
    }

    public function redirect(string $location, int $status = 302): self
    {
        $this->status = $status;
        $this->headers['Location'] = $location;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function emit(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $key => $value) {
                if (strcasecmp($key, 'Set-Cookie') === 0) {
                    continue;
                }
                header($key . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie->toString(), false);
            }
        }
        echo $this->body;
    }
}
