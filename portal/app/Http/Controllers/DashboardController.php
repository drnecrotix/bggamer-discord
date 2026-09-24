<?php

namespace App\Http\Controllers;

use App\Services\Discord;
use App\Services\PortalServer;
use App\Services\GitHubUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class DashboardController extends Controller
{
    public function index(Discord $discord, PortalServer $server)
    {
        $guild = null;
        $events = [];
        $error = null;
        try {
            $guild = Cache::remember('discord.guild.'.$server->guildId(), 60, fn () => $discord->bot('/guilds/'.$server->guildId().'?with_counts=true'));
            $events = Cache::remember('discord.events.'.$server->guildId(), 60, fn () => $discord->bot('/guilds/'.$server->guildId().'/scheduled-events'));
        } catch (Throwable $exception) {
            report($exception);
            $error = 'Discord is unavailable. Metrics are hidden until the connection recovers.';
        }
        $updateAvailable = false;
        if (session()->has('owner_id')) {
            try { $updateAvailable = app(GitHubUpdater::class)->available(app(GitHubUpdater::class)->latest()); }
            catch (Throwable $exception) { report($exception); }
        }
        return view('dashboard', [
            'updateAvailable' => $updateAvailable,
            'guild' => $guild,
            'server' => $server->settings(),
            'events' => $events,
            'error' => $error,
            'activity' => DB::table('portal_audit')->latest()->limit(10)->get(),
        ]);
    }

    public function announce(Request $request, Discord $discord)
    {
        abort_unless($request->session()->get('staff.level') === 'admin', 403);
        $data = $request->validate(['content' => 'required|string|min:2|max:1900']);
        try {
            $message = $discord->publish($data['content']);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['content' => 'Discord rejected the announcement. Check bot permissions and channel ID.']);
        }
        DB::table('portal_audit')->insert([
            'actor_id' => $request->session()->get('staff.id'),
            'action' => 'announcement.published',
            'subject_id' => $message['id'] ?? null,
            'created_at' => now(),
        ]);
        return back()->with('status', 'Announcement published.');
    }
}
