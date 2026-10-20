<?php

declare(strict_types=1);

namespace Kabuto\Mail;

use RuntimeException;

final class StreamSmtpSession implements SmtpSession
{
    /** @param resource $stream */
    public function __construct(private $stream, private readonly float $timeout, private readonly bool $verifyPeer = true)
    {
        stream_set_timeout($this->stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));
    }

    public function readResponse(): array
    {
        $lines = [];
        $code = 0;
        do {
            $line = fgets($this->stream, 8192);
            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);
                throw new RuntimeException(($meta['timed_out'] ?? false) ? 'SMTP response timed out.' : 'SMTP connection closed while reading response.');
            }
            $line = rtrim($line, "\r\n");
            if (!preg_match('/^(\d{3})([- ])(.*)$/', $line, $match)) {
                throw new RuntimeException('Malformed SMTP response line.');
            }
            $lineCode = (int) $match[1];
            if ($code === 0) {
                $code = $lineCode;
            } elseif ($lineCode !== $code) {
                throw new RuntimeException('Inconsistent SMTP multiline response codes.');
            }
            $lines[] = $match[3];
            $more = $match[2] === '-';
        } while ($more);

        return ['code' => $code, 'lines' => $lines];
    }

    public function write(string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP connection failed while writing.');
            }
            $offset += $written;
        }
    }

    public function enableTls(): bool
    {
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        }
        // PHP verifies peer + peer name by default; context options are still
        // explicitly set on connect so neither can be silently disabled.
        return stream_socket_enable_crypto($this->stream, true, $method) === true;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
