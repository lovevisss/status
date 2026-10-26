<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_only_the_configured_proxy_can_report_an_https_request(): void
    {
        config()->set('trustedproxy.proxies', '10.0.0.2');

        Route::middleware('web')->get('/proxy-scheme-test', fn (Request $request) => $request->isSecure() ? 'https' : 'http');

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/proxy-scheme-test')->assertOk()->assertSeeText('https');

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/proxy-scheme-test')->assertOk()->assertSeeText('http');
    }
}
