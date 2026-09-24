<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class KnowledgeController extends Controller
{
    public function index()
    {
        $items = Schema::hasTable('knowledge_items')
            ? KnowledgeItem::orderBy('sort_order')->orderBy('id')->get()->groupBy('type')
            : collect();
        return view('knowledge', compact('items'));
    }

    public function manage()
    {
        abort_unless(Schema::hasTable('knowledge_items'), 503, 'Apply portal database migrations first.');
        return view('knowledge-manage', ['items' => KnowledgeItem::orderBy('sort_order')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:command,faq',
            'title' => 'required|string|max:160',
            'body' => 'required|string|max:3000',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);
        KnowledgeItem::create($data);
        return back()->with('status', 'Записът е добавен.');
    }

    public function destroy(KnowledgeItem $item)
    {
        $item->delete();
        return back()->with('status', 'Записът е премахнат.');
    }
}
