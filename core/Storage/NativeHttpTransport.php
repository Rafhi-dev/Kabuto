<?php

declare(strict_types=1);

namespace Kabuto\Storage;

/** Zero-dependency HTTP transport based on PHP streams with TLS verification enabled by default. */
final class NativeHttpTransport
{
    /** @return array{status:int,body:string,headers:array<string,string>} */
    public function __invoke(string $method, string $uri, array $headers = [], ?string $body = null, float $timeout = 15.0): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', (string) $name) || preg_match('/[\r\n]/', (string) $value)) {
                throw new \InvalidArgumentException('HTTP header names and values must not contain line breaks.');
            }
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => max(0.1, $timeout),
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($uri, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        $headersOut = [];
        foreach ($responseHeaders as $index => $line) {
            if ($index === 0 && preg_match('/\s(\d{3})(?:\s|$)/', $line, $match)) {
                $status = (int) $match[1];
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $headersOut[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
        }

        return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : '', 'headers' => $headersOut];
    }
}
