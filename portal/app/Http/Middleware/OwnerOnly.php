<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OwnerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('owner_id');
        abort_unless($id && DB::table('portal_owners')->where('id', $id)->exists(), 403);
        return $next($request);
    }
}
