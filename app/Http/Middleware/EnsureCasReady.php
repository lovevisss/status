<?php

namespace App\Http\Middleware;

use App\Services\CasAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCasReady
{
    public function __construct(private readonly CasAvailability $availability) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->availability->ready($request), 404);

        return $next($request);
    }
}
