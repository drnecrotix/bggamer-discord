<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OwnerAuthController extends Controller
{
    public function form()
    {
        return view('owner-login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string',
        ]);
        $owner = DB::table('portal_owners')->where('email', mb_strtolower($credentials['email']))->first();
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
