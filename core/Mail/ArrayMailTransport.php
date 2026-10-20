<?php

declare(strict_types=1);

namespace Kabuto\Mail;

final class ArrayMailTransport implements MailTransport
{
    /** @var list<array{to:list<string>,cc:list<string>,bcc:list<string>,subject:string,text:string,attachments:list<array{path:string,name:?string,mime:?string}>}> */
    private array $messages = [];

    public function send(MailMessage $message): void { $this->messages[] = $message->toArray(); }

    public function messages(): array { return $this->messages; }
}
