<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OwnerSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $owner = DB::table('portal_owners')->where('id', $request->session()->get('owner_id'))->first();
        abort_unless($owner, 403);
        return view('owner-settings', compact('owner'));
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
