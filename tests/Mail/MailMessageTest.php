<?php

declare(strict_types=1);

namespace Kabuto\Tests\Mail;

use Kabuto\Mail\ArrayMailTransport;
use Kabuto\Mail\MailManager;
use Kabuto\Mail\MailMessage;
use Kabuto\Tests\TestCase;

final class MailMessageTest extends TestCase
{
    public function testHtmlHeadersAndRecipientsArePreserved(): void
    {
        $transport = new ArrayMailTransport();
        $message = (new MailMessage())->to('a@example.test')->cc('c@example.test')->subject('Hello')->text('plain')->html('<b>hello</b>')->header('X-Test', '1');
        (new MailManager($transport))->send($message);
        self::assertSame('<b>hello</b>', $transport->messages()[0]['html']);
        self::assertSame('1', $transport->messages()[0]['headers']['X-Test']);
    }

    public function testMessageSenderReplyToAndAttachmentMetadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto-attach-');
        file_put_contents($path, 'data');
        try {
            $message = (new MailMessage('Hi', 'Text'))
                ->from('sender@example.test', 'Kabuto')
                ->replyTo('reply@example.test')
                ->attach($path, 'data.txt', 'text/plain');
            $data = $message->toArray();
            self::assertSame('Kabuto <sender@example.test>', $data['from']);
            self::assertSame('reply@example.test', $data['reply_to']);
            self::assertSame('data.txt', $data['attachments'][0]['name']);
            self::assertSame('text/plain', $data['attachments'][0]['mime']);
        } finally {
            @unlink($path);
        }
    }
}
