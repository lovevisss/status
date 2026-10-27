<?php

namespace App\Services;

use Illuminate\Http\Request;

final class CasAvailability
{
    public function ready(Request $request): bool
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        return config('cas.enabled') === true
            && $request->isSecure()
            && str_starts_with($appUrl, 'https://')
            && config('cas.service_url') === $appUrl.'/auth/cas/callback';
    }
}
