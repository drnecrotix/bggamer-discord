<?php

namespace App\Livewire;

use App\Services\Discord;
use App\Services\PortalServer;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

class GuildStatus extends Component
{
    public function render()
    {
        $guild = null;
        $discord = app(Discord::class);
        $server = app(PortalServer::class);
        $guildId = $server->guildId();
        if (preg_match('/^\d{17,20}$/', $guildId)) {
            try {
                $guild = Cache::remember('discord.guild.'.$guildId, 60,
                    fn () => $discord->bot('/guilds/'.$guildId.'?with_counts=true'));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        return view('livewire.guild-status', ['guild' => $guild]);
    }
}
