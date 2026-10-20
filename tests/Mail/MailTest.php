<?php

declare(strict_types=1);

namespace Kabuto\Tests\Mail;

use Kabuto\Mail\ArrayMailTransport;
use Kabuto\Mail\MailManager;
use Kabuto\Mail\MailMessage;
use Kabuto\Tests\TestCase;

final class MailTest extends TestCase
{
    public function testMessageIsSentThroughTransport(): void
    {
        $transport = new ArrayMailTransport();
        $manager = new MailManager($transport);
        $manager->send((new MailMessage())->to('user@example.test')->subject('Welcome')->text('Hello'));
        self::assertCount(1, $transport->messages());
        self::assertSame('Welcome', $transport->messages()[0]['subject']);
    }

    public function testMessageRequiresRecipientAndSubject(): void
    {
        $manager = new MailManager(new ArrayMailTransport());
        self::assertThrows(\RuntimeException::class, fn () => $manager->send(new MailMessage()));
        self::assertThrows(\RuntimeException::class, fn () => $manager->send((new MailMessage())->to('a@example.test')));
    }
}
