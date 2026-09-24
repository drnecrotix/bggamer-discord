<?php

namespace App\Http\Controllers;

use App\Services\DiscordSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OwnerSettingsController extends Controller
{
    public function edit(Request $request, DiscordSettings $discord)
    {
        $owner = DB::table('portal_owners')->where('id', $request->session()->get('owner_id'))->first();
        abort_unless($owner, 403);
        return view('owner-settings', [
            'owner' => $owner,
            'discord' => $discord->all(),
            'callbackUrl' => $discord->redirectUri(),
            'schemaReady' => Schema::hasTable('support_tickets') && Schema::hasTable('knowledge_items')
                && Schema::hasTable('discord_ban_appeals') && Schema::hasTable('roles'),
        ]);
    }

    public function updateDiscord(Request $request, DiscordSettings $discord)
    {
        $owner = DB::table('portal_owners')->where('id', $request->session()->get('owner_id'))->first();
        abort_unless($owner, 403);
        $data = $request->validate([
            'client_id' => ['nullable', 'regex:/^\d{17,20}$/'],
            'client_secret' => 'nullable|string|max:256',
            'bot_token' => 'nullable|string|max:256',
            'admin_role_ids' => 'nullable|string|max:400',
            'moderator_role_ids' => 'nullable|string|max:400',
            'support_role_ids' => 'nullable|string|max:400',
            'announcement_channel_id' => ['nullable', 'regex:/^\d{17,20}$/'],
            'current_password' => 'required|string',
        ]);
        if (! Hash::check($data['current_password'], $owner->password)) {
            return back()->withErrors(['current_password' => 'Невалидна Owner парола.']);
        }
        $settings = [
            'client_id' => $data['client_id'] ?? null,
            'client_secret' => $data['client_secret'] ?? null,
            'bot_token' => $data['bot_token'] ?? null,
            'announcement_channel_id' => $data['announcement_channel_id'] ?? null,
        ];
        foreach (['admin_role_ids', 'moderator_role_ids', 'support_role_ids'] as $key) {
            $ids = array_filter(array_map('trim', explode(',', $data[$key] ?? '')));
            if (count($ids) > 10 || count(array_filter($ids, fn ($id) => ! preg_match('/^\d{17,20}$/', $id)))) {
                return back()->withErrors([$key => 'Въведи до 10 Discord role ID, разделени със запетая.']);
            }
            $settings[$key] = array_values(array_unique($ids));
        }
        $discord->save($settings);
        DB::table('portal_audit')->insert([
            'actor_id' => 'owner:'.$owner->id, 'action' => 'discord.credentials.updated',
            'subject_id' => null, 'created_at' => now(),
        ]);
        return back()->with('status', 'Discord настройките са запазени.');
    }

    public function migrate(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string']);
        $owner = DB::table('portal_owners')->where('id', $request->session()->get('owner_id'))->first();
        abort_unless($owner && Hash::check($data['current_password'], $owner->password), 403);
        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            if ($exitCode !== 0 || ! Schema::hasTable('support_tickets') || ! Schema::hasTable('knowledge_items')
                || ! Schema::hasTable('discord_ban_appeals') || ! Schema::hasTable('roles')) {
                return back()->withErrors(['migration' => 'Миграцията не завърши. Провери Laravel лога и правата на базата.']);
            }
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['migration' => 'Миграцията не завърши. Провери Laravel лога и правата на базата.']);
        }
        return back()->with('status', 'Новите таблици са готови.');
    }

    public function unlink(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        $owner = DB::table('portal_owners')->where('id', $request->session()->get('owner_id'))->first();
        abort_unless($owner, 403);
        if (! Hash::check($data['password'], $owner->password)) {
            return back()->withErrors(['password' => 'Invalid password.']);
        }
        DB::table('portal_owners')->where('id', $owner->id)->update([
            'discord_id' => null, 'updated_at' => now(),
        ]);
        DB::table('portal_audit')->insert([
            'actor_id' => 'owner:'.$owner->id, 'action' => 'owner.discord.unlinked',
            'subject_id' => $owner->discord_id, 'created_at' => now(),
        ]);
        return back()->with('status', 'Discord account disconnected.');
    }
}
