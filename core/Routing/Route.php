<?php

declare(strict_types=1);

namespace Kabuto\Routing;

final class Route
{
    private string $regex;

    /** @var list<string> */
    private array $paramNames = [];

    /**
     * @param list<callable|string> $middleware
     */
    public function __construct(
        private readonly string $method,
        private readonly string $pattern,
        private readonly mixed $handler,
        private readonly array $middleware = [],
        private readonly array $metadata = [],
    ) {
        [$this->regex, $this->paramNames] = self::compile($pattern);
    }

    /**
     * Compile an Express-style pattern into a regex.
     *  - `:name`  -> named parameter, matches a single path segment
     *  - `*`      -> wildcard, matches anything (rest of the path)
     *
     * Repeated wildcards are numbered (wildcard, wildcard2, ...). Repeated
     * named parameters are rejected with a clear error instead of a PCRE
     * compilation warning at match time.
     *
     * @return array{0:string,1:list<string>}
     */
    private static function compile(string $pattern): array
    {
        $paramNames = [];
        $regex = '';
        $length = strlen($pattern);
        $wildcards = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === ':') {
                $name = '';
                while ($i + 1 < $length && (ctype_alnum($pattern[$i + 1]) || $pattern[$i + 1] === '_')) {
                    $name .= $pattern[++$i];
                }
                if ($name === '') {
                    $regex .= ':';
                    continue;
                }
                if (in_array($name, $paramNames, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Duplicate route parameter [:%s] in pattern [%s].',
                        $name,
                        $pattern,
                    ));
                }
                $paramNames[] = $name;
                $regex .= '(?P<' . $name . '>[^/]+)';
                continue;
            }

            if ($char === '*') {
                $wildcards++;
                $name = $wildcards === 1 ? 'wildcard' : 'wildcard' . $wildcards;
                $paramNames[] = $name;
                $regex .= '(?P<' . $name . '>.*)';
                continue;
            }

            $regex .= preg_quote($char, '#');
        }

        return ['#^' . $regex . '$#', $paramNames];
    }

    public function method(): string
    {
        return $this->method;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    /** @return list<callable|string> */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function metadata(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->metadata : ($this->metadata[$key] ?? $default);
    }

    /**
     * @return array<string,string>|null matched params, or null on no match
     */
    public function matches(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $m)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            if (isset($m[$name])) {
                $params[$name] = rawurldecode((string) $m[$name]);
            }
        }
        return $params;
    }
}
