<?php

namespace App\Http\Controllers;

use App\Services\Discord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DiscordAuthController extends Controller
{
    public function redirect(Request $request)
    {
        return $this->authorize($request, 'login');
    }

    public function link(Request $request)
    {
        abort_unless($request->session()->get('owner_id'), 403);
        return $this->authorize($request, 'link');
    }

    private function authorize(Request $request, string $mode)
    {
        if (! config('discord.client_id') || ! config('discord.client_secret') || ! config('discord.redirect_uri')) {
            return redirect()->route('login')->withErrors(['auth' => 'Discord OAuth не е настроен. Owner може да влезе с имейл и парола.']);
        }
        $state = Str::random(48);
        $request->session()->put('discord_oauth_state', $state);
        $request->session()->put('discord_oauth_mode', $mode);
        return redirect('https://discord.com/oauth2/authorize?'.http_build_query([
            'client_id' => config('discord.client_id'),
            'redirect_uri' => config('discord.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'identify',
            'state' => $state,
        ]));
    }

    public function callback(Request $request, Discord $discord)
    {
        $state = $request->session()->pull('discord_oauth_state');
        $mode = $request->session()->pull('discord_oauth_mode', 'login');
        abort_unless($state && is_string($request->query('state')) && hash_equals($state, $request->query('state')), 403);
        $request->validate(['code' => 'required|string|max:2048']);
        try {
            $token = Http::asForm()->timeout(8)->post('https://discord.com/api/v10/oauth2/token', [
                'client_id' => config('discord.client_id'),
                'client_secret' => config('discord.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
                'redirect_uri' => config('discord.redirect_uri'),
            ])->throw()->json('access_token');
            $identity = Http::withToken($token)->timeout(8)->get('https://discord.com/api/v10/users/@me')->throw()->json();
        } catch (Throwable $exception) {
            report($exception);
            return redirect('/')->withErrors(['auth' => 'Discord login could not be verified.']);
        }
        abort_unless(isset($identity['id']) && preg_match('/^\d{17,20}$/', (string) $identity['id']), 403);
        if ($mode === 'link') {
            $ownerId = $request->session()->get('owner_id');
            abort_unless($ownerId && DB::table('portal_owners')->where('id', $ownerId)->exists(), 403);
            abort_if(DB::table('portal_owners')->where('discord_id', $identity['id'])->where('id', '!=', $ownerId)->exists(), 409);
            DB::table('portal_owners')->where('id', $ownerId)->update([
                'discord_id' => $identity['id'], 'updated_at' => now(),
            ]);
            DB::table('portal_audit')->insert([
                'actor_id' => 'owner:'.$ownerId, 'action' => 'owner.discord.linked',
                'subject_id' => $identity['id'], 'created_at' => now(),
            ]);
            return redirect()->route('owner.settings')->with('status', 'Discord account linked.');
        }
        $owner = DB::table('portal_owners')->where('discord_id', $identity['id'])->first();
        if ($owner) {
            $request->session()->regenerate();
            $request->session()->put('owner_id', $owner->id);
            $request->session()->put('staff', [
                'id' => 'owner:'.$owner->id, 'name' => 'Owner', 'level' => 'admin',
            ]);
            return redirect()->route('dashboard');
        }
        try {
            $member = $discord->member($identity['id']);
        } catch (Throwable $exception) {
            report($exception);
            return redirect('/')->withErrors(['auth' => 'Discord guild membership could not be verified.']);
        }
        $roles = $member['roles'] ?? [];
        $level = count(array_intersect($roles, config('discord.admin_role_ids'))) ? 'admin'
            : (count(array_intersect($roles, config('discord.moderator_role_ids'))) ? 'moderator'
                : (count(array_intersect($roles, config('discord.support_role_ids'))) ? 'support' : null));
        abort_unless($level, 403, 'A configured staff role is required.');
        $request->session()->regenerate();
        $request->session()->put('staff', [
            'id' => $identity['id'],
            'name' => $member['nick'] ?? $identity['global_name'] ?? $identity['username'],
            'level' => $level,
        ]);
        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}
