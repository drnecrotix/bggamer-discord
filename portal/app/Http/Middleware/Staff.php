<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Staff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->session()->has('staff'), 403);
        return $next($request);
    }
}
