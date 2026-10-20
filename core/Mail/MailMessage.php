<?php

declare(strict_types=1);

namespace Kabuto\Mail;

final class MailMessage
{
    /** @var list<string> */
    private array $to = [];
    /** @var list<string> */
    private array $cc = [];
    /** @var list<string> */
    private array $bcc = [];
    /** @var list<array{path:string,name:?string,mime:?string}> */
    private array $attachments = [];

    private ?string $htmlBody = null;
    /** @var array<string,string> */
    private array $headers = [];
    private ?string $fromAddress = null;
    private ?string $replyToAddress = null;
    private ?string $messageId = null;
    private string $textSubtype = 'plain';

    public function __construct(private string $subject = '', private string $text = '') {}

    public function subject(string $subject): self { $this->subject = $subject; return $this; }
    public function text(string $text): self { $this->text = $text; return $this; }
    public function html(string $html): self { $this->htmlBody = $html; return $this; }
    public function from(string $address, ?string $name = null): self { $this->fromAddress = $name !== null ? $name . ' <' . $address . '>' : $address; return $this; }
    public function sender(string $address): self { $this->fromAddress = $address; return $this; }
    public function replyTo(string $address, ?string $name = null): self { $this->replyToAddress = $name !== null ? $name . ' <' . $address . '>' : $address; return $this; }
    public function messageId(string $messageId): self { $this->messageId = $messageId; return $this; }
    public function textSubtype(string $subtype): self { $this->textSubtype = strtolower($subtype); return $this; }
    public function header(string $name, string $value): self { $this->headers[$name] = $value; return $this; }
    public function removeHeader(string $name): self { unset($this->headers[$name]); return $this; }
    public function toAddresses(): array { return $this->to; }
    public function to(string ...$addresses): self { array_push($this->to, ...$addresses); return $this; }
    public function cc(string ...$addresses): self { array_push($this->cc, ...$addresses); return $this; }
    public function bcc(string ...$addresses): self { array_push($this->bcc, ...$addresses); return $this; }
    public function attach(string $path, ?string $name = null, ?string $mime = null): self
    {
        if (!is_file($path)) throw new \InvalidArgumentException("Attachment does not exist: {$path}");
        if (!is_readable($path)) throw new \InvalidArgumentException("Attachment is not readable: {$path}");
        $name ??= basename($path);
        if (preg_match('/[\r\n\x00]/', $name)) throw new \InvalidArgumentException('Attachment filename contains invalid characters.');
        $mime ??= function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null;
        $this->attachments[] = ['path' => $path, 'name' => $name, 'mime' => $mime];
        return $this;
    }

    /** @return array{to:list<string>,cc:list<string>,bcc:list<string>,subject:string,text:string,html:?string,headers:array<string,string>,attachments:list<array{path:string,name:?string,mime:?string}>,from:?string,reply_to:?string,message_id:?string,text_subtype:string} */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'text' => $this->text,
            'html' => $this->htmlBody,
            'headers' => $this->headers,
            'attachments' => $this->attachments,
            'from' => $this->fromAddress,
            'reply_to' => $this->replyToAddress,
            'message_id' => $this->messageId,
            'text_subtype' => $this->textSubtype,
        ];
    }
}
