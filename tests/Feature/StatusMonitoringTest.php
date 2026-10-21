<?php

namespace Tests\Feature;

use App\Models\MonitorCheck;
use App\Services\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatusMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_api_shows_pending_confirmed_and_stale_states(): void
    {
        config()->set('monitors.alerts.recipients', '[{"id":"20230001","email":"person@example.edu.cn"}]');
        Http::fake([
            'https://www.zufedfc.edu.cn*' => Http::response('OK', 200),
            'https://party.zufedfc.edu.cn*' => Http::response('Unavailable', 503),
            'http://webvpn.zufedfc.edu.cn/*' => Http::response('Login', 200),
        ]);

        $this->postJson('/api/status/check')->assertNotFound();
        app(MonitorRunner::class)->run();
        $this->getJson('/api/status')->assertOk()
            ->assertJsonPath('services.0.state', 'up')
            ->assertJsonPath('services.1.state', 'pending')
            ->assertJsonPath('services.1.status_code', 503)
            ->assertJsonPath('services.2.state', 'up')
            ->assertJsonPath('historyDays', 30);
        $this->assertSame(3, MonitorCheck::count());

        app(MonitorRunner::class)->run();
        $this->getJson('/api/status')->assertJsonPath('services.1.state', 'down')
            ->assertJsonCount(1, 'incidents');

        $this->travel(6)->minutes();
        $this->getJson('/api/status')->assertJsonPath('services.1.state', 'unknown')
            ->assertJsonPath('services.1.has_gaps', true);
    }
}
