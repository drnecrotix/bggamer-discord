<?php

namespace App\Http\Controllers;

use App\Models\BanAppeal;
use App\Models\DiscordBan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BanAppealController extends Controller
{
    public function create(Request $request)
    {
        return view('appeal', ['reference' => $request->query('ban', '')]);
    }

    public function store(Request $request)
    {
        abort_unless(Schema::hasTable('discord_ban_appeals'), 503, 'Appeal setup is pending.');
        $data = $request->validate([
            'ban_reference' => 'nullable|string|max:24',
            'discord_user_id' => ['required', 'regex:/^\d{17,20}$/'],
            'discord_username' => 'required|string|max:80',
            'contact' => 'required|string|max:190',
            'appeal_reason' => 'required|string|max:190',
            'additional_information' => 'required|string|min:20|max:5000',
            'website' => 'nullable|max:0',
        ]);
        $banId = null;
        if (! empty($data['ban_reference'])) {
            $ban = Schema::hasTable('discord_bans')
                ? DiscordBan::where('public_reference', $data['ban_reference'])->first() : null;
            if (! $ban || $ban->discord_user_id !== $data['discord_user_id']) {
                return back()->withErrors(['ban_reference' => 'Ban reference и Discord User ID не съвпадат.'])->withInput();
            }
            $banId = $ban->id;
        }
        $appeal = BanAppeal::create([
            'public_reference' => 'AP-'.strtoupper(Str::random(14)),
            'ban_id' => $banId,
            'discord_user_id' => $data['discord_user_id'],
            'discord_username' => $data['discord_username'],
            'contact' => $data['contact'],
            'appeal_reason' => $data['appeal_reason'],
            'additional_information' => $data['additional_information'],
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        return redirect()->route('appeals.create')->with('appeal_reference', $appeal->public_reference);
    }

    public function index()
    {
        abort_unless(Schema::hasTable('discord_ban_appeals'), 503, 'Apply portal migrations first.');
        return view('appeals', ['appeals' => BanAppeal::latest('submitted_at')->paginate(20)]);
    }

    public function lookup(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'regex:/^AP-[A-Z0-9]{14}$/'],
            'discord_user_id' => ['required', 'regex:/^\d{17,20}$/'],
        ]);
        $appeal = BanAppeal::where('public_reference', $data['reference'])
            ->where('discord_user_id', $data['discord_user_id'])->first();
        return back()->with('lookup', $appeal ? [
            'reference' => $appeal->public_reference,
            'status' => $appeal->status,
            'reply' => $appeal->moderator_response,
        ] : ['error' => 'Няма намерено обжалване с тези данни.']);
    }

    public function update(Request $request, BanAppeal $appeal)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,under_review,information_requested,approved,rejected,closed',
            'moderator_response' => 'nullable|string|max:5000',
        ]);
        $appeal->update($data + [
            'reviewed_by' => (string) $request->session()->get('staff.id'),
            'reviewed_at' => now(),
        ]);
        return back()->with('status', 'Обжалването е обновено.');
    }
}
