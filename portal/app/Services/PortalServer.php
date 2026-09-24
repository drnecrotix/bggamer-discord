<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PortalServer
{
    public function settings(): array
    {
        $saved = Schema::hasTable('discord_portal_settings')
            ? DB::table('discord_portal_settings')->where('id', 1)->first()
            : null;

        return [
            'guild_id' => $saved?->guild_id ?: config('discord.guild_id'),
            'invite_code' => $saved?->invite_code ?: config('discord.invite_code'),
        ];
    }

    public function guildId(): string
    {
        return (string) $this->settings()['guild_id'];
    }

    public function inviteUrl(): ?string
    {
        $code = $this->settings()['invite_code'];
        return $code && preg_match('/^[A-Za-z0-9-]{2,64}$/', $code)
            ? 'https://discord.gg/'.$code : null;
    }
}
