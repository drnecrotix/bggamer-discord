<?php
namespace App\Http\Middleware;

use App\Services\Discord;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Staff
{
    public function handle(Request $request, Closure $next, string $minimum = 'support'): Response
    {
        $ownerId = $request->session()->get('owner_id');
        if ($ownerId) {
            $owner = DB::table('portal_owners')->where('id', $ownerId)->exists();
            abort_unless($owner, 403);
            return $next($request);
        }
        $staff = $request->session()->get('staff');
        abort_unless(is_array($staff) && isset($staff['id']), 403);
        $id = (string) $staff['id'];
        abort_unless(preg_match('/^\d{17,20}$/', $id), 403);
        try {
            $roles = Cache::remember('staff.roles.'.$id, 60, fn () => app(Discord::class)->member($id)['roles'] ?? []);
        } catch (Throwable $exception) {
            report($exception);
            abort(503, 'Cannot verify Discord staff role.');
        }
        $level = count(array_intersect($roles, app(\App\Services\DiscordSettings::class)->get('admin_role_ids'))) ? 'admin'
            : (count(array_intersect($roles, app(\App\Services\DiscordSettings::class)->get('moderator_role_ids'))) ? 'moderator'
                : (count(array_intersect($roles, app(\App\Services\DiscordSettings::class)->get('support_role_ids'))) ? 'support' : null));
        abort_unless($level, 403);
        if (Schema::hasTable('roles') && Auth::user() instanceof User) {
            $user = Auth::user();
            abort_unless($user->discord_id === $id, 403);
            Role::findOrCreate($level, 'web');
            $user->syncRoles([$level]);
            abort_unless($user->hasRole($level), 403);
        }
        $request->session()->put('staff.level', $level);
        $levels = ['support' => 1, 'moderator' => 2, 'admin' => 3];
        abort_unless($levels[$level] >= ($levels[$minimum] ?? 3), 403);
        return $next($request);
    }
}
