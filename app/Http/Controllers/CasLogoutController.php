<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lovevisss\LaravelZufedfcCas\CasClient;
use Lovevisss\LaravelZufedfcCas\Http\Controllers\LogoutController;
use Symfony\Component\HttpFoundation\Response;

final class CasLogoutController
{
    public function __invoke(Request $request, LogoutController $logout, CasClient $cas): RedirectResponse|Response
    {
        $response = $logout($request, $cas);

        return $request->header('X-Inertia')
            ? Inertia::location($response->getTargetUrl())
            : $response;
    }
}
