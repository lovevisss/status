<?php

namespace App\Http\Middleware;

use App\Services\MonitorAdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMonitorAdmin
{
    public function __construct(private readonly MonitorAdminAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->access->allows($request->user(), $request->session()->get('auth.via_cas') === true), 403);

        return $next($request);
    }
}
