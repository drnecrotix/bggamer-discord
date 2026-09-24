<?php

namespace App\Http\Controllers;

use App\Services\GitHubUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpdateController extends Controller
{
    public function index(GitHubUpdater $updater)
    {
        try { $release = $updater->latest(); }
        catch (Throwable $exception) { report($exception); $release = null; }
        $current = DB::table('portal_updates')->latest()->value('version') ?? '0.1.0';
        return view('updates', compact('release', 'current'));
    }

    public function apply(Request $request, GitHubUpdater $updater)
    {
        $data = $request->validate(['version' => 'required|string|max:80']);
        try {
            $release = $updater->latest();
            if (! $release || ! ($release['ready'] ?? false)
                || $release['version'] !== $data['version']
                || version_compare(ltrim($release['version'], 'v'),
                    ltrim(DB::table('portal_updates')->latest()->value('version') ?? '0.1.0', 'v'), '<=')) {
                return back()->withErrors(['version' => 'No verified newer release is available.']);
            }
            $count = $updater->apply($release);
            DB::table('portal_updates')->insert([
                'version' => $release['version'], 'digest' => $release['digest'],
                'actor_id' => 'owner:'.$request->session()->get('owner_id'), 'created_at' => now(),
            ]);
            return back()->with('status', "Updated $count files to {$release['version']}.");
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['version' => 'Update failed. Files were restored when possible; inspect server logs.']);
        }
    }
}
