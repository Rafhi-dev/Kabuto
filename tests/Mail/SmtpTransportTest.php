<?php

declare(strict_types=1);

namespace Kabuto\Tests\Mail;

use Kabuto\Mail\SmtpException;
use Kabuto\Mail\SmtpTransport;
use Kabuto\Tests\Fixtures\FakeSmtpSession;
use Kabuto\Tests\TestCase;

final class SmtpTransportTest extends TestCase
{
    public function testStartTlsCapabilityRefreshAndAuthPlainNegotiation(): void
    {
        $session = new FakeSmtpSession([
            ['code' => 220, 'lines' => ['ready']],
            ['code' => 250, 'lines' => ['mail.example', 'STARTTLS', 'AUTH LOGIN PLAIN']],
            ['code' => 220, 'lines' => ['begin tls']],
            ['code' => 250, 'lines' => ['mail.example', 'AUTH PLAIN']],
            ['code' => 235, 'lines' => ['authenticated']],
            ['code' => 250, 'lines' => ['sender ok']],
            ['code' => 250, 'lines' => ['recipient ok']],
            ['code' => 354, 'lines' => ['send data']],
            ['code' => 250, 'lines' => ['accepted']],
            ['code' => 221, 'lines' => ['bye']],
        ]);
        $connectorCalls = [];
        $transport = new SmtpTransport(
            host: 'smtp.example.test', port: 587, username: 'user', password: 'pass',
            from: 'sender@example.test', tls: true, retries: 0,
            connector: function (string $host, int $port, float $timeout, bool $implicitTls) use ($session, &$connectorCalls): FakeSmtpSession {
                $connectorCalls[] = [$host, $port, $timeout, $implicitTls];
                return $session;
            },
        );

        $transport->send((new \Kabuto\Mail\MailMessage('Hello', 'Body'))->to('to@example.test'));

        self::assertSame(1, $session->tlsUpgrades);
        self::assertTrue($session->closed);
        self::assertSame([['smtp.example.test', 587, 10.0, false]], $connectorCalls);
        self::assertTrue(str_contains(implode('', $session->writes), 'AUTH PLAIN '));
    }

    public function testHealthProbeConnectsAndClosesWithoutSendingMail(): void
    {
        $session = new FakeSmtpSession([
            ['code' => 220, 'lines' => ['ready']],
            ['code' => 250, 'lines' => ['mail.example']],
            ['code' => 221, 'lines' => ['bye']],
        ]);
        $transport = new SmtpTransport(
            host: 'smtp.example.test',
            from: 'sender@example.test',
            tls: false,
            retries: 0,
            connector: static fn (): FakeSmtpSession => $session,
        );

        self::assertTrue($transport->health());
        self::assertTrue($session->closed);
        self::assertFalse(str_contains(implode('', $session->writes), 'MAIL FROM'));
    }

    public function testCapabilitiesSupportAuthEqualsSyntaxAndLoginFallback(): void
    {
        $session = new FakeSmtpSession([
            ['code' => 220, 'lines' => ['ready']],
            ['code' => 250, 'lines' => ['mail.example', 'AUTH=LOGIN']],
            ['code' => 334, 'lines' => ['username']],
            ['code' => 334, 'lines' => ['password']],
            ['code' => 235, 'lines' => ['authenticated']],
            ['code' => 250, 'lines' => ['sender ok']],
            ['code' => 250, 'lines' => ['recipient ok']],
            ['code' => 354, 'lines' => ['data']],
            ['code' => 250, 'lines' => ['accepted']],
            ['code' => 221, 'lines' => ['bye']],
        ]);
        $transport = $this->transport($session, tls: false, port: 465, allowInsecureAuth: true);
        $transport->send((new \Kabuto\Mail\MailMessage('Hi', 'body'))->to('to@example.test'));
        self::assertTrue(str_contains(implode('', $session->writes), "AUTH LOGIN\r\n"));
    }

    public function testRefusesPlaintextAuthAndTlsDowngrade(): void
    {
        $authSession = new FakeSmtpSession([
            ['code' => 220, 'lines' => ['ready']],
            ['code' => 250, 'lines' => ['mail.example', 'AUTH LOGIN']],
        ]);
        self::assertThrows(SmtpException::class, fn () => $this->transport($authSession, tls: false, port: 587)->send((new \Kabuto\Mail\MailMessage('Hi'))->to('to@example.test')));

        $noTlsSession = new FakeSmtpSession([
            ['code' => 220, 'lines' => ['ready']],
            ['code' => 250, 'lines' => ['mail.example']],
        ]);
        $transport = $this->transport($noTlsSession, tls: true, port: 587);
        self::assertThrows(\RuntimeException::class, fn () => $transport->send((new \Kabuto\Mail\MailMessage('Hi'))->to('to@example.test')));
        self::assertFalse(str_contains(implode('', $noTlsSession->writes), 'AUTH PLAIN'));
    }

    public function testBuildsMultipartMimeWithBase64AttachmentAndBccEnvelopeOnly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto-mail-');
        file_put_contents($path, "binary\0payload\xFF");
        try {
            $session = new FakeSmtpSession([
                ['code' => 220, 'lines' => ['ready']],
                ['code' => 250, 'lines' => ['mail.example', 'AUTH PLAIN']],
                ['code' => 235, 'lines' => ['authenticated']],
                ['code' => 250, 'lines' => ['sender ok']],
                ['code' => 250, 'lines' => ['recipient ok']],
                ['code' => 250, 'lines' => ['hidden ok']],
                ['code' => 354, 'lines' => ['data']],
                ['code' => 250, 'lines' => ['accepted']],
                ['code' => 221, 'lines' => ['bye']],
            ]);
            $message = (new \Kabuto\Mail\MailMessage('Résumé', 'plain body'))
                ->to('Visible <to@example.test>')->bcc('hidden@example.test')
                ->html('<b>html body</b>')->attach($path, 'résumé.bin', 'application/octet-stream');
            $this->transport($session, tls: true, port: 465)->send($message);
            $wire = implode('', $session->writes);
            self::assertTrue(str_contains($wire, 'RCPT TO:<hidden@example.test>'));
            self::assertFalse(str_contains($wire, 'Bcc:'));
            self::assertTrue(str_contains($wire, 'multipart/mixed'));
            self::assertTrue(str_contains($wire, 'multipart/alternative'));
            self::assertTrue(str_contains($wire, base64_encode("binary\0payload\xFF")));
            self::assertTrue(str_contains($wire, 'filename*=UTF-8\'\'r%C3%A9sum%C3%A9.bin'));
            self::assertTrue(str_contains($wire, '=?UTF-8?B?'));
        } finally {
            @unlink($path);
        }
    }

    public function testDotStuffingProtectsDataTerminator(): void
    {
        $transport = $this->transport(new FakeSmtpSession([]), tls: true, port: 465);
        $method = new \ReflectionMethod($transport, 'dotStuff');
        $stuffed = $method->invoke($transport, ".\r\nInjected\n.body");
        self::assertTrue(str_starts_with($stuffed, '..'));
        self::assertTrue(str_contains($stuffed, '..body'));
    }

    public function testPermanentSmtpErrorsAreNotRetried(): void
    {
        $calls = 0;
        $transport = new SmtpTransport(
            'smtp.example.test', from: 'sender@example.test', retries: 3,
            connector: function () use (&$calls): FakeSmtpSession {
                $calls++;
                return new FakeSmtpSession([
                    ['code' => 220, 'lines' => ['ready']],
                    ['code' => 250, 'lines' => ['mail.example']],
                    ['code' => 550, 'lines' => ['sender rejected']],
                ]);
            },
        );
        self::assertThrows(\RuntimeException::class, fn () => $transport->send((new \Kabuto\Mail\MailMessage('Hi'))->to('to@example.test')));
        self::assertSame(1, $calls);
    }

    public function testRejectsInvalidSenderAndMissingAttachment(): void
    {
        $session = new FakeSmtpSession([]);
        $transport = $this->transport($session, tls: true, port: 465);
        self::assertThrows(\RuntimeException::class, fn () => $transport->send((new \Kabuto\Mail\MailMessage('Hi'))->to('to@example.test')));
        self::assertThrows(\InvalidArgumentException::class, fn () => (new \Kabuto\Mail\MailMessage())->attach('missing-file'));
    }

    private function transport(FakeSmtpSession $session, bool $tls = true, bool $allowInsecureAuth = false, int $port = 587): SmtpTransport
    {
        return new SmtpTransport(
            host: 'smtp.example.test', port: $port, username: 'user', password: 'pass',
            from: 'sender@example.test', tls: $tls, retries: 0,
            connector: static fn () => $session,
            allowInsecureAuth: $allowInsecureAuth,
        );
    }
}
