<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicBansController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate(['q' => 'nullable|string|max:80']);
        $bans = null;
        if (Schema::hasTable('discord_bans')) {
            $bans = DB::table('discord_bans')
                ->select('public_reference', 'username', 'global_name', 'public_reason', 'banned_at', 'expires_at', 'status')
                ->whereIn('status', ['active', 'temporary'])
                ->when($filter['q'] ?? null, fn ($query, $term) => $query->where(function ($q) use ($term) {
                    $q->where('username', 'like', '%'.$term.'%')->orWhere('public_reference', 'like', '%'.$term.'%');
                }))
                ->orderByDesc('banned_at')->paginate(15)->withQueryString();
        }
        return view('bans', compact('bans'));
    }
}
