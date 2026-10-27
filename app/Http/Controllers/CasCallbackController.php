<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lovevisss\LaravelZufedfcCas\CasClient;
use Lovevisss\LaravelZufedfcCas\Contracts\CasIdentityResolver;
use Lovevisss\LaravelZufedfcCas\Exceptions\CasUnavailableException;
use Lovevisss\LaravelZufedfcCas\Exceptions\InvalidTicketException;
use Lovevisss\LaravelZufedfcCas\Http\Controllers\CallbackController;
use Lovevisss\LaravelZufedfcCas\SessionRegistry;

final class CasCallbackController
{
    public function __invoke(
        Request $request,
        CallbackController $callback,
        CasClient $cas,
        CasIdentityResolver $resolver,
        SessionRegistry $sessions,
    ): RedirectResponse {
        try {
            return $callback($request, $cas, $resolver, $sessions);
        } catch (InvalidTicketException|CasUnavailableException) {
            return redirect()->route('login')->with('status', '统一认证未完成，请稍后重试。');
        }
    }
}
