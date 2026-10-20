<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use Closure;
use RuntimeException;

final class WebhookNotificationChannel implements NotificationChannel
{
    public function __construct(private readonly string $url, private readonly ?string $secret = null, private readonly ?Closure $request = null) {}
    public function send(mixed $notifiable, Notification $notification): void
    {
        $data = method_exists($notification, 'toWebhook') ? $notification->toWebhook($notifiable) : (method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : ['notification' => $notification::class]);
        $body = json_encode($data, JSON_THROW_ON_ERROR); $headers = ['Content-Type' => 'application/json'];
        if ($this->secret !== null) $headers['X-Kabuto-Signature'] = hash_hmac('sha256', $body, $this->secret);
        if ($this->request !== null) { $response = ($this->request)($this->url, $body, $headers); if ($response < 200 || $response >= 300) throw new RuntimeException("Webhook failed with status {$response}."); return; }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers)), 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
        $result = @file_get_contents($this->url, false, $context); $status = (int) substr($http_response_header[0] ?? '000', 9, 3); if ($result === false || $status < 200 || $status >= 300) throw new RuntimeException('Webhook delivery failed.');
    }
}
