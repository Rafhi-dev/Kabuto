<?php

declare(strict_types=1);

namespace Kabuto\Mail;

use RuntimeException;

final class SmtpTransport implements MailTransport
{
    /** @var callable(string,int,float,bool):resource|SmtpSession|null */
    private readonly ?\Closure $connector;

    /** @param callable(string,int,float,bool):resource|SmtpSession|null $connector */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly string $from = '',
        private readonly bool $tls = true,
        private readonly int $retries = 1,
        private readonly float $timeout = 10.0,
        ?callable $connector = null,
        private readonly string $ehloName = 'kabuto.local',
        private readonly bool $allowInsecureAuth = false,
    ) {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SMTP host and valid port are required.');
        }
        if ($timeout <= 0 || $retries < 0) {
            throw new \InvalidArgumentException('SMTP timeout must be positive and retries cannot be negative.');
        }
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host) || !preg_match('/^[A-Za-z0-9.-]+$/', $ehloName)) {
            throw new \InvalidArgumentException('SMTP host and EHLO name must be valid host names.');
        }
        if (($username === null) !== ($password === null)) {
            throw new \InvalidArgumentException('SMTP username and password must be provided together.');
        }
        $this->connector = $connector === null ? null : $connector(...);
    }

    public function send(MailMessage $message): void
    {
        $attempts = max(1, $this->retries + 1);
        $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->sendOnce($message);
                return;
            } catch (\Throwable $e) {
                $last = $e;
                $retryable = $e instanceof SmtpException ? $e->retryable : true;
                if (!$retryable || $attempt >= $attempts) {
                    break;
                }
                usleep(min(1_000_000, 100_000 * (2 ** ($attempt - 1))));
            }
        }

        if ($last instanceof SmtpException) {
            throw $last;
        }

        throw new RuntimeException('SMTP delivery failed: ' . ($last?->getMessage() ?? 'unknown error'), 0, $last);
    }

    public function health(): bool
    {
        $session = null;
        try {
            $session = $this->connect();
            $greeting = $session->readResponse();
            if ($greeting['code'] !== 220) throw new SmtpException('SMTP health probe received an invalid greeting.', false, $greeting['code']);
            $this->ehlo($session);
            $this->command($session, 'QUIT', [221], 'health QUIT');
            $session->close();
            return true;
        } catch (\Throwable) {
            $session?->close();
            return false;
        }
    }

    private function sendOnce(MailMessage $message): void
    {
        $data = $message->toArray();
        $recipients = array_values(array_unique(array_merge($data['to'], $data['cc'], $data['bcc'])));
        if ($recipients === []) {
            throw new SmtpException('SMTP message requires at least one recipient.', false);
        }
        $this->validateHeaders($data);
        if (strlen((string) $data['subject']) > 900) {
            throw new SmtpException('SMTP subject is too long for safe header encoding.', false);
        }
        $from = ($data['from'] ?? null) ?: $this->from;
        if ($from === '') {
            throw new SmtpException('SMTP sender address is not configured.', false);
        }
        $this->validateHeaderValue($from, 'from');
        if (($data['reply_to'] ?? null) !== null) {
            $this->validateHeaderValue($data['reply_to'], 'reply-to');
        }
        if (($data['message_id'] ?? null) !== null) {
            $this->validateHeaderValue($data['message_id'], 'message id');
        }
        foreach ($data['headers'] as $headerName => $headerValue) {
            $this->validateHeaderValue((string) $headerName, 'header name');
            $this->validateHeaderValue((string) $headerValue, 'header value');
        }
        foreach ($data['attachments'] as $attachment) {
            $this->validateHeaderValue((string) ($attachment['name'] ?? basename($attachment['path'])), 'attachment filename');
            if (!is_file($attachment['path']) || !is_readable($attachment['path'])) {
                throw new SmtpException('SMTP attachment is missing or unreadable: ' . $attachment['path'], false);
            }
        }
        $envelopeSender = preg_match('/<([^<>]+)>/', $from, $fromMatch) ? trim($fromMatch[1]) : $from;
        $fromAddress = $this->mailboxAddress($envelopeSender, 'from');
        foreach ($recipients as $recipient) {
            $this->mailboxAddress($recipient, 'recipient');
        }
        // Materialize and validate all MIME content before entering SMTP DATA;
        // local file/encoding failures must never leave the server awaiting a
        // body or cause an unnecessary retry.
        $mime = $this->mime($data, $from);

        $session = $this->connect();
        try {
            $this->expect($session, 220, 'connect');
            $capabilities = $this->ehlo($session);

            if ($this->tls && $this->port !== 465) {
                if (!isset($capabilities['STARTTLS'])) {
                    throw new SmtpException('SMTP server does not advertise STARTTLS; refusing plaintext fallback.', false);
                }
                $this->command($session, 'STARTTLS', [220], 'STARTTLS');
                if (!$session->enableTls()) {
                    throw new SmtpException('SMTP TLS negotiation or peer certificate validation failed.', true);
                }
                // Capabilities must be refreshed after the encrypted channel starts.
                $capabilities = $this->ehlo($session);
            }

            if ($this->username !== null) {
                $this->authenticate($session, $capabilities);
            }

            $this->command($session, 'MAIL FROM:<' . $fromAddress . '>', [250], 'MAIL FROM');
            foreach ($recipients as $recipient) {
                $this->command($session, 'RCPT TO:<' . $this->mailboxAddress($recipient, 'recipient') . '>', [250, 251], 'RCPT TO');
            }
            $this->command($session, 'DATA', [354], 'DATA');
            $session->write($this->dotStuff($mime) . "\r\n.\r\n");
            try {
                $this->expect($session, 250, 'message body');
            } catch (\Throwable $e) {
                // The server may have accepted the message even if the final
                // response was lost; retrying could create a duplicate.
                throw new SmtpException('SMTP delivery outcome is uncertain after DATA; not retrying.', false, null, $e);
            }
            try {
                $this->command($session, 'QUIT', [221], 'QUIT');
            } catch (SmtpException) {
                // The message has been accepted; QUIT failure must not cause a
                // retry that could deliver it twice.
            }
        } finally {
            $session->close();
        }
    }

    private function connect(): SmtpSession
    {
        $implicitTls = $this->tls && $this->port === 465;
        if ($this->connector !== null) {
            $connected = ($this->connector)($this->host, $this->port, $this->timeout, $implicitTls);
        } else {
            $context = stream_context_create(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $this->host,
            ]]);
            $scheme = $implicitTls ? 'ssl://' : 'tcp://';
            $connected = @stream_socket_client($scheme . $this->host . ':' . $this->port, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
            if (!is_resource($connected)) {
                throw new SmtpException('SMTP connection failed: ' . ($error ?? 'network error'), true, $errno ?: null);
            }
        }

        if ($connected instanceof SmtpSession) {
            return $connected;
        }
        if (is_resource($connected)) {
            return new StreamSmtpSession($connected, $this->timeout);
        }
        throw new SmtpException('SMTP connector did not return a stream or SmtpSession.', true);
    }

    /** @return array<string,list<string>> */
    private function ehlo(SmtpSession $session): array
    {
        try {
            $response = $this->command($session, 'EHLO ' . $this->ehloName, [250], 'EHLO');
        } catch (SmtpException $e) {
            // RFC 5321 HELO fallback is useful for old relays; it has no ESMTP
            // extensions, so AUTH/STARTTLS will remain unavailable.
            if ($e->smtpCode !== 500 && $e->smtpCode !== 502 && $e->smtpCode !== 504) {
                throw $e;
            }
            $this->command($session, 'HELO ' . $this->ehloName, [250], 'HELO');
            return [];
        }

        $capabilities = [];
        foreach ($response['lines'] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $first = strtoupper((string) array_shift($parts));
            $name = $first;
            if (str_starts_with($first, 'AUTH=')) {
                $parts = array_merge([substr($first, 5)], $parts);
                $name = 'AUTH';
            }
            if ($name === 'AUTH' && isset($parts[0]) && strtoupper($parts[0]) === '=') {
                array_shift($parts);
            }
            $capabilities[$name] = array_map('strtoupper', $parts);
        }
        return $capabilities;
    }

    /** @param array<string,list<string>> $capabilities */
    private function authenticate(SmtpSession $session, array $capabilities): void
    {
        $mechanisms = $capabilities['AUTH'] ?? [];
        if ($mechanisms === [] && $this->allowInsecureAuth && $this->username !== null) {
            $mechanisms = ['LOGIN'];
        }
        if ($mechanisms === []) {
            throw new SmtpException('SMTP authentication requested but the server did not advertise AUTH.', false);
        }

        if (in_array('PLAIN', $mechanisms, true)) {
            if (!$this->tls && !$this->allowInsecureAuth) {
                throw new SmtpException('Refusing AUTH PLAIN over an unencrypted SMTP connection.', false);
            }
            $payload = base64_encode("\0" . $this->username . "\0" . $this->password);
            $response = $this->command($session, 'AUTH PLAIN ' . $payload, [235, 334], 'AUTH PLAIN');
            if ($response['code'] === 334) {
                $this->command($session, $payload, [235], 'AUTH PLAIN response');
            }
            return;
        }

        if (in_array('LOGIN', $mechanisms, true)) {
            if (!$this->tls && !$this->allowInsecureAuth) {
                throw new SmtpException('Refusing AUTH LOGIN over an unencrypted SMTP connection.', false);
            }
            $this->command($session, 'AUTH LOGIN', [334], 'AUTH LOGIN');
            $this->command($session, base64_encode((string) $this->username), [334], 'AUTH LOGIN username');
            $this->command($session, base64_encode((string) $this->password), [235], 'AUTH LOGIN password');
            return;
        }

        throw new SmtpException('SMTP server advertises no supported AUTH mechanism (PLAIN or LOGIN).', false);
    }

    /** @param list<int> $expected @return array{code:int,lines:list<string>} */
    private function command(SmtpSession $session, string $command, array $expected, string $operation): array
    {
        if (preg_match('/[\r\n]/', $command)) {
            throw new SmtpException("Invalid SMTP command for [{$operation}].", false);
        }
        $session->write($command . "\r\n");
        return $this->expect($session, $expected, $operation);
    }

    /** @param int|list<int> $expected @return array{code:int,lines:list<string>} */
    private function expect(SmtpSession $session, int|array $expected, string $operation): array
    {
        $response = $session->readResponse();
        $codes = is_array($expected) ? $expected : [$expected];
        if (!in_array($response['code'], $codes, true)) {
            $retryable = $response['code'] >= 400 && $response['code'] < 500;
            throw new SmtpException(
                sprintf('SMTP %s failed with %d: %s', $operation, $response['code'], implode(' | ', $response['lines'])),
                $retryable,
                $response['code'],
            );
        }
        return $response;
    }

    /** @param array<string,mixed> $data */
    private function validateHeaders(array $data): void
    {
        $this->validateHeaderValue((string) ($data['subject'] ?? ''), 'subject');
        foreach (['to', 'cc', 'bcc'] as $field) {
            foreach ($data[$field] as $address) {
                $this->validateHeaderValue((string) $address, $field);
            }
        }
        foreach ($data['headers'] as $name => $value) {
            $this->validateHeaderValue((string) $name, 'header name');
            $this->validateHeaderValue((string) $value, 'header value');
            if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', (string) $name)) {
                throw new SmtpException('Invalid SMTP header name.', false);
            }
            if (in_array(strtolower((string) $name), ['to', 'cc', 'bcc', 'from', 'sender', 'subject', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding'], true)) {
                throw new SmtpException("SMTP custom header [{$name}] is reserved.", false);
            }
        }
        foreach ($data['attachments'] as $attachment) {
            $this->validateHeaderValue((string) ($attachment['name'] ?? ''), 'attachment filename');
            if (!is_file($attachment['path']) || !is_readable($attachment['path'])) {
                throw new SmtpException('SMTP attachment is missing or unreadable: ' . $attachment['path'], false);
            }
        }
    }

    private function validateHeaderValue(string $value, string $field): void
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw new SmtpException("Invalid SMTP {$field} header.", false);
        }
    }

    private function mailboxAddress(string $value, string $field): string
    {
        $this->validateHeaderValue($value, $field);
        $address = trim($value);
        if (preg_match('/<([^<>]+)>/', $address, $match)) {
            $address = trim($match[1]);
        }
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new SmtpException("Invalid SMTP {$field} address.", false);
        }
        return $address;
    }

    /** @param array<string,mixed> $data */
    private function mime(array $data, string $from): string
    {
        $headers = [
            'From: ' . $this->encodeAddressHeader($from),
            'To: ' . $this->addressList($data['to']),
        ];
        if ($data['cc'] !== []) {
            $headers[] = 'Cc: ' . $this->addressList($data['cc']);
        }
        $headers[] = 'Subject: ' . $this->encodeHeader((string) $data['subject']);
        $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
        $messageId = $data['message_id'] ?? null;
        if ($messageId !== null) {
            $this->validateHeaderValue($messageId, 'message id');
            $headers[] = 'Message-ID: ' . (str_starts_with($messageId, '<') ? $messageId : '<' . $messageId . '>');
        } else {
            $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->ehloName . '>';
        }
        if (($data['reply_to'] ?? null) !== null) {
            $headers[] = 'Reply-To: ' . $this->encodeAddressHeader($data['reply_to']);
        }
        foreach ($data['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $this->encodeHeaderValue((string) $value);
        }

        $headers[] = 'MIME-Version: 1.0';
        $subtype = preg_match('/^[a-z0-9.+-]+$/', $data['text_subtype'] ?? 'plain') ? $data['text_subtype'] : 'plain';
        $textPart = $this->base64Part(
            "text/{$subtype}; charset=UTF-8",
            $this->normalizeCrlf((string) $data['text']),
        );
        $body = $textPart;
        $bodyContentType = "text/{$subtype}; charset=UTF-8";
        if ($data['html'] !== null) {
            $altBoundary = 'kabuto_alt_' . bin2hex(random_bytes(12));
            $bodyContentType = 'multipart/alternative; boundary="' . $altBoundary . '"';
            $body = '--' . $altBoundary . "\r\n" . $textPart . "\r\n"
                . '--' . $altBoundary . "\r\n" . $this->base64Part('text/html; charset=UTF-8', (string) $data['html']) . "\r\n"
                . '--' . $altBoundary . '--';
        }

        if ($data['attachments'] !== []) {
            $mixedBoundary = 'kabuto_mixed_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';
            $body = '--' . $mixedBoundary . "\r\nContent-Type: {$bodyContentType}\r\n\r\n" . $body;

            foreach ($data['attachments'] as $attachment) {
                $contents = file_get_contents($attachment['path']);
                if (!is_string($contents)) {
                    throw new SmtpException('Unable to read SMTP attachment: ' . $attachment['path'], false);
                }
                $filename = $attachment['name'] ?? basename($attachment['path']);
                $mime = $attachment['mime'] ?? (function_exists('mime_content_type') ? mime_content_type($attachment['path']) : false) ?: 'application/octet-stream';
                $this->validateHeaderValue($filename, 'attachment filename');
                $this->validateHeaderValue($mime, 'attachment content type');
                $encodedFilename = rawurlencode($filename);
                $encodedType = $this->headerParameter($mime);
                $encodedName = $this->headerParameter($filename);
                $body .= "\r\n--{$mixedBoundary}\r\n"
                    . 'Content-Type: ' . $encodedType . '; name="' . $encodedName . '"' . "\r\n"
                    . 'Content-Disposition: attachment; filename="' . $encodedName . '"; filename*=UTF-8\'\'' . $encodedFilename . "\r\n"
                    . "Content-Transfer-Encoding: base64\r\n\r\n"
                    . rtrim(chunk_split(base64_encode($contents), 76, "\r\n"));
            }
            $body .= "\r\n--{$mixedBoundary}--";
        } else {
            $headers[] = 'Content-Type: ' . $bodyContentType;
        }

        return implode("\r\n", $headers) . "\r\n" . $body;
    }

    private function base64Part(string $contentType, string $contents): string
    {
        return 'Content-Type: ' . $contentType . "\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($contents), 76, "\r\n"));
    }

    private function addressList(array $addresses): string
    {
        return implode(', ', array_map(fn (string $address): string => $this->encodeAddressHeader($address), $addresses));
    }

    private function encodeAddressHeader(string $address): string
    {
        if (preg_match('/^(.+?)\s*<([^<>]+)>$/', trim($address), $match)) {
            $display = trim($match[1]);
            $this->validateHeaderValue($display, 'display name');
            return $this->encodeHeader($display) . ' <' . $this->mailboxAddress($match[2], 'recipient') . '>';
        }
        return $this->mailboxAddress($address, 'recipient');
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $this->encodeHeaderValue($value);
    }

    private function encodeHeaderValue(string $value): string
    {
        $this->validateHeaderValue($value, 'header value');
        return $value;
    }

    private function normalizeCrlf(string $value): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
    }

    private function headerParameter(string $value): string
    {
        return addcslashes($value, "\\\"");
    }

    private function dotStuff(string $message): string
    {
        $message = $this->normalizeCrlf($message);
        return preg_replace('/(?m)^\./', '..', $message) ?? $message;
    }
}
