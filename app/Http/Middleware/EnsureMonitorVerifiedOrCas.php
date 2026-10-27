<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMonitorVerifiedOrCas
{
    public function __construct(private readonly EnsureEmailIsVerified $verified) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('auth.via_cas') === true) {
            return $next($request);
        }

        return $this->verified->handle($request, $next);
    }
}
