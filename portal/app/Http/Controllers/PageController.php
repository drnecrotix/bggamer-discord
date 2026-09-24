<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    private function defaults(): array
    {
        return [
            'title' => 'BG-GAMER Discord Community',
            'subtitle' => 'Играй, общувай и намери своя отбор.',
            'body' => 'Българска общност за игри, технологии, събития и съвместни сесии.',
            'cta_label' => 'Присъедини се',
            'cta_url' => 'https://discord.gg/PFkjeKBuxH',
        ];
    }

    public function home()
    {
        $page = Schema::hasTable('portal_pages') ? DB::table('portal_pages')->where('slug', 'home')->first() : null;
        return view('home', ['page' => (object) array_merge($this->defaults(), (array) $page)]);
    }

    public function edit()
    {
        $page = DB::table('portal_pages')->where('slug', 'home')->first();
        return view('edit-home', ['page' => (object) array_merge($this->defaults(), (array) $page)]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'subtitle' => 'required|string|max:240',
            'body' => 'required|string|max:3000',
            'cta_label' => 'required|string|max:80',
            'cta_url' => ['required', 'url', 'max:500', Rule::notIn(['javascript:', 'data:'])],
        ]);
        if (! str_starts_with($data['cta_url'], 'https://')) {
            return back()->withErrors(['cta_url' => 'Use a secure HTTPS link.'])->withInput();
        }
        DB::transaction(function () use ($request, $data) {
            DB::table('portal_pages')->updateOrInsert(['slug' => 'home'], array_merge($data, [
                'updated_by' => $request->session()->get('staff.id'),
                'updated_at' => now(),
            ]));
            DB::table('portal_audit')->insert([
                'actor_id' => $request->session()->get('staff.id'),
                'action' => 'homepage.updated',
                'subject_id' => 'home',
                'created_at' => now(),
            ]);
        });
        return back()->with('status', 'Homepage updated.');
    }
}
