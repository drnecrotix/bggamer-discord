<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Discord
{
    private const API = 'https://discord.com/api/v10';

    public function bot(string $path): array
    {
        $response = Http::withToken(config('discord.bot_token'))->timeout(8)->get(self::API.$path);
        if (! $response->successful()) {
            throw new RuntimeException('Discord API unavailable ('.$response->status().')');
        }
        return $response->json() ?? [];
    }

    public function widget(string $guildId): array
    {
        if (! preg_match('/^\d{17,20}$/', $guildId)) {
            throw new RuntimeException('Invalid Discord guild ID.');
        }
        $response = Http::timeout(6)->get(self::API.'/guilds/'.$guildId.'/widget.json');
        if (! $response->successful()) {
            throw new RuntimeException('Discord widget unavailable ('.$response->status().')');
        }
        return $response->json() ?? [];
    }

    public function member(string $userId): array
    {
        return $this->bot('/guilds/'.app(PortalServer::class)->guildId().'/members/'.$userId);
    }

    public function publish(string $content): array
    {
        $channel = config('discord.announcement_channel_id');
        if (! $channel || ! preg_match('/^\d{17,20}$/', $channel)) {
            throw new RuntimeException('Announcement channel is not configured.');
        }
        $response = Http::withToken(config('discord.bot_token'))->timeout(8)
            ->post(self::API.'/channels/'.$channel.'/messages', [
                'content' => $content,
                'allowed_mentions' => ['parse' => []],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('Discord rejected the message ('.$response->status().')');
        }
        return $response->json();
    }
}
