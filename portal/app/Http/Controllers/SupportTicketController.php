<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportTicketController extends Controller
{
    public function create()
    {
        return view('support');
    }

    public function store(Request $request)
    {
        abort_unless(Schema::hasTable('support_tickets'), 503, 'Support setup is pending.');
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'discord_user_id' => ['nullable', 'regex:/^\d{17,20}$/'],
            'subject' => 'required|string|min:5|max:160',
            'message' => 'required|string|min:20|max:5000',
            'website' => 'nullable|max:0',
        ]);
        unset($data['website']);
        $data['email'] = mb_strtolower($data['email']);
        $ticket = SupportTicket::create($data + ['reference' => 'BG-'.strtoupper(Str::random(12))]);
        return redirect()->route('support.create')->with('ticket_reference', $ticket->reference);
    }

    public function index()
    {
        abort_unless(Schema::hasTable('support_tickets'), 503, 'Apply portal database migrations first.');
        return view('tickets', ['tickets' => SupportTicket::latest()->paginate(20)]);
    }

    public function lookup(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'regex:/^BG-[A-Z0-9]{12}$/'],
            'email' => 'required|email|max:255',
        ]);
        $ticket = SupportTicket::where('reference', $data['reference'])
            ->where('email', strtolower($data['email']))->first();
        return back()->with('lookup', $ticket ? [
            'reference' => $ticket->reference,
            'status' => $ticket->status,
            'reply' => $ticket->reply,
        ] : ['error' => 'Няма намерена заявка с тези данни.']);
    }

    public function update(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'status' => 'required|in:open,in_progress,resolved',
            'reply' => 'nullable|string|max:5000',
        ]);
        $ticket->update($data);
        return back()->with('status', 'Статусът е обновен.');
    }
}
