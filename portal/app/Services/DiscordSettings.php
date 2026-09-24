<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class DiscordSettings
{
    public function __construct(private ?string $file = null) {}

    private function path(): string
    {
        return $this->file ?? storage_path('app/private/discord-settings.enc');
    }

    public function all(): array
    {
        $stored = [];
        if (is_file($this->path())) {
            $stored = json_decode(Crypt::decryptString(file_get_contents($this->path())), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($stored)) {
                throw new RuntimeException('Invalid Discord settings.');
            }
        }
        return array_replace(config('discord'), $stored);
    }

    public function get(string $name): mixed
    {
        return $this->all()[$name] ?? null;
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/discord/callback';
    }

    public function ready(): bool
    {
        return (bool) ($this->get('client_id') && $this->get('client_secret') && $this->redirectUri());
    }

    public function save(array $settings): void
    {
        $previous = $this->all();
        foreach (['client_secret', 'bot_token'] as $secret) {
            if (empty($settings[$secret])) {
                $settings[$secret] = $previous[$secret] ?? null;
            }
        }
        $path = $this->path();
        $directory = dirname($path);
        if (is_link($directory) || is_link($path) || ! is_dir($directory)) {
            throw new RuntimeException('Private settings directory is unavailable.');
        }
        $temp = tempnam($directory, 'discord-');
        if ($temp === false) {
            throw new RuntimeException('Cannot write Discord settings.');
        }
        try {
            $ciphertext = Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR));
            if (file_put_contents($temp, $ciphertext, LOCK_EX) === false || ! chmod($temp, 0600) || ! rename($temp, $path)) {
                throw new RuntimeException('Cannot save Discord settings.');
            }
        } finally {
            if (is_file($temp)) { @unlink($temp); }
        }
    }
}
