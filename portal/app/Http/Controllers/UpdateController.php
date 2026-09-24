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
        $current = $updater->currentVersion();
        $available = $updater->available($release);
        return view('updates', compact('release', 'current', 'available'));
    }

    public function check(GitHubUpdater $updater)
    {
        try { $updater->latest(true); }
        catch (Throwable $exception) { report($exception); return back()->withErrors(['version' => 'GitHub check failed.']); }
        return back()->with('status', 'GitHub check completed.');
    }

    public function apply(Request $request, GitHubUpdater $updater)
    {
        $data = $request->validate(['version' => 'required|string|max:80']);
        try {
            $release = $updater->latest(true);
            if (! $release || ! ($release['ready'] ?? false)
                || $release['version'] !== $data['version']
                || ! $updater->available($release)) {
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
