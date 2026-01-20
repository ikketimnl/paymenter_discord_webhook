<?php

namespace Paymenter\Extensions\Others\paymenter_discord_webhook\src\Services;

use Illuminate\Support\Facades\Http;

class DiscordWebhook
{
    public function __construct(
        protected string $webhookUrl,
        protected ?string $username = null,
        protected ?string $avatarUrl = null,
        protected int $timeoutSeconds = 5,
    ) {}

    public function send(array $payload): void
    {
        if (!$this->webhookUrl) return;

        if ($this->username) $payload['username'] = $this->username;
        if ($this->avatarUrl) $payload['avatar_url'] = $this->avatarUrl;

        Http::timeout($this->timeoutSeconds)
            ->retry(2, 250)
            ->post($this->webhookUrl, $payload);
    }
}
