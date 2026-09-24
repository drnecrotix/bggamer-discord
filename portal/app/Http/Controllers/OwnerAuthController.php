<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OwnerAuthController extends Controller
{
    public function form()
    {
        return view('login', [
            'discordReady' => config('discord.client_id') && config('discord.client_secret') && config('discord.redirect_uri'),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string',
        ]);
        try {
            if (! Schema::hasTable('portal_owners')) {
                return back()->withErrors(['email' => 'Инсталацията не е завършена. Отвори /install.php и довърши настройката.']);
            }
            $owner = DB::table('portal_owners')->where('email', mb_strtolower($credentials['email']))->first();
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['email' => 'Базата данни не е достъпна. Провери настройките и логовете на сървъра.']);
        }
        if (! $owner || ! Hash::check($credentials['password'], $owner->password)) {
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }
        $request->session()->regenerate();
        $request->session()->forget('staff');
        $request->session()->put('owner_id', $owner->id);
        $request->session()->put('staff', [
            'id' => 'owner:'.$owner->id,
            'name' => 'Owner',
            'level' => 'admin',
        ]);
        return redirect()->route('dashboard');
    }
}
