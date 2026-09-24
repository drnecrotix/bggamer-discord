<?php

namespace App\Http\Controllers;

use App\Models\PortalPage;
use App\Services\Discord;
use App\Services\PortalServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    private function defaults(): array
    {
        return [
            'title' => 'BG-GAMER Discord Community',
            'subtitle' => 'Играй, общувай и намери своя отбор.',
            'body' => 'Българска общност за игри, технологии, събития и съвместни сесии.',
            'cta_label' => 'Присъедини се',
            'cta_url' => 'https://discord.gg/PFkjeKBuxH',
        ];
    }

    public function home(Discord $discord, PortalServer $server)
    {
        $page = Schema::hasTable('portal_pages') ? PortalPage::find('home') : null;
        $guild = null;
        $error = false;
        $widget = null;
        $guildId = $server->guildId();
        if (preg_match('/^\d{17,20}$/', $guildId) && app(\App\Services\DiscordSettings::class)->get('bot_token')) {
            try {
                $guild = Cache::remember('discord.guild.'.$guildId, 60,
                    fn () => $discord->bot('/guilds/'.$guildId.'?with_counts=true'));
            } catch (Throwable $exception) {
                report($exception);
                $error = true;
            }
        } else {
            $error = true;
        }
        if (preg_match('/^\d{17,20}$/', $guildId)) {
            try {
                $widget = Cache::remember('discord.widget.'.$guildId, 60,
                    fn () => $discord->widget($guildId));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $members = collect($widget['members'] ?? [])
            ->filter(fn ($member) => is_array($member)
                && isset($member['avatar_url'])
                && is_string($member['avatar_url'])
                && preg_match('~^https://(?:cdn\.discordapp\.com|media\.discordapp\.net)/~', $member['avatar_url']))
            ->take(8)
            ->map(fn ($member) => [
                'name' => mb_substr((string) ($member['username'] ?? 'Member'), 0, 64),
                'avatar' => $member['avatar_url'],
            ])->values()->all();
        $voiceCount = $widget === null ? null : collect($widget['members'] ?? [])
            ->filter(fn ($member) => is_array($member) && ! empty($member['channel_id']))->count();
        return view('home', [
            'page' => (object) array_merge($this->defaults(), ($page?->toArray() ?? [])),
            'guild' => $guild,
            'widget' => $widget,
            'members' => $members,
            'voiceCount' => $voiceCount,
            'error' => $error,
            'inviteUrl' => $server->inviteUrl(),
        ]);
    }

    public function edit()
    {
        $page = PortalPage::find('home');
        return view('edit-home', ['page' => (object) array_merge($this->defaults(), ($page?->toArray() ?? []))]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'subtitle' => 'required|string|max:240',
            'body' => 'required|string|max:3000',
            'cta_label' => 'required|string|max:80',
            'cta_url' => ['required', 'url', 'max:500', Rule::notIn(['javascript:', 'data:'])],
        ]);
        if (! str_starts_with($data['cta_url'], 'https://')) {
            return back()->withErrors(['cta_url' => 'Use a secure HTTPS link.'])->withInput();
        }
        DB::transaction(function () use ($request, $data) {
            PortalPage::updateOrCreate(['slug' => 'home'], array_merge($data, [
                'updated_by' => $request->session()->get('staff.id'),
            ]));
            DB::table('portal_audit')->insert([
                'actor_id' => $request->session()->get('staff.id'),
                'action' => 'homepage.updated',
                'subject_id' => 'home',
                'created_at' => now(),
            ]);
        });
        return back()->with('status', 'Homepage updated.');
    }
}
