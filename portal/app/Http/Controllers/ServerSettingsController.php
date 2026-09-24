<?php

namespace App\Http\Controllers;

use App\Services\Discord;
use App\Services\PortalServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServerSettingsController extends Controller
{
    public function edit(PortalServer $server)
    {
        return view('server-settings', ['settings' => $server->settings()]);
    }

    public function update(Request $request, Discord $discord)
    {
        $data = $request->validate([
            'guild_id' => ['required', 'regex:/^\d{17,20}$/'],
            'invite_code' => ['nullable', 'regex:/^[A-Za-z0-9-]{2,64}$/'],
        ]);
        try {
            $guild = $discord->bot('/guilds/'.$data['guild_id'].'?with_counts=true');
            if (($guild['id'] ?? null) !== $data['guild_id']) {
                return back()->withErrors(['guild_id' => 'The bot could not verify this server.'])->withInput();
            }
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['guild_id' => 'The bot cannot access this server. Check its guild membership and permissions.'])->withInput();
        }
        DB::transaction(function () use ($data, $request) {
            DB::table('discord_portal_settings')->updateOrInsert(['id' => 1], [
                'guild_id' => $data['guild_id'],
                'invite_code' => $data['invite_code'] ?? null,
                'updated_by' => $request->session()->get('staff.id'),
                'updated_at' => now(),
            ]);
            DB::table('portal_audit')->insert([
                'actor_id' => $request->session()->get('staff.id'),
                'action' => 'server.settings.updated',
                'subject_id' => $data['guild_id'],
                'created_at' => now(),
            ]);
        });
        Cache::forget('discord.guild.'.$data['guild_id']);
        return redirect()->route('server.edit')->with('status', 'Discord server updated.');
    }
}
