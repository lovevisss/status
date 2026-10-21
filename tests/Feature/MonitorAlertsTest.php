<?php

namespace Tests\Feature;

use App\Models\MonitorAlert;
use App\Models\MonitorIncident;
use App\Services\MonitorAlertDispatcher;
use App\Services\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitorAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('monitors.services', [[
            'key' => 'portal', 'name' => '学校官网', 'description' => '学校主页',
            'url' => 'https://www.zufedfc.edu.cn',
        ]]);
        config()->set('monitors.alerts', [
            'api_url' => 'http://messager.test/api/v1/notifications',
            'api_token' => 'test-token',
            'recipients' => json_encode([
                ['id' => '20230001', 'email' => 'one@example.edu.cn'],
                ['id' => '20230002', 'email' => 'two@example.edu.cn'],
            ]),
        ]);
    }

    public function test_two_failures_create_one_alert_per_recipient_and_one_success_recovers(): void
    {
        $statuses = [503, 503, 503, 200];
        Http::fake(function (Request $request) use (&$statuses) {
            if (str_contains($request->url(), 'www.zufedfc.edu.cn')) {
                return Http::response('', array_shift($statuses));
            }

            return Http::response(['id' => 'job-'.$request->data()['recipients'][0]['id'], 'status' => 'accepted'], 202);
        });

        $runner = app(MonitorRunner::class);
        $runner->run();
        $this->assertSame(0, MonitorAlert::count());
        $runner->run();
        $runner->run();
        $this->assertSame(2, MonitorAlert::where('kind', 'outage')->count());
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame(2, MonitorAlert::where('status', 'accepted')->count());

        $runner->run();
        $this->assertSame(2, MonitorAlert::where('kind', 'recovery')->count());
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame(4, MonitorAlert::where('status', 'accepted')->count());
        $this->assertNotNull(MonitorIncident::first()->recovered_at);
        $this->assertSame(4, MonitorAlert::pluck('request_id')->unique()->count());
    }

    public function test_transient_failure_and_gap_reset_do_not_alert(): void
    {
        $statuses = [503, 200, 503, 503];
        Http::fake(function () use (&$statuses) {
            return Http::response('', array_shift($statuses));
        });

        $runner = app(MonitorRunner::class);
        $runner->run();
        $runner->run();
        $runner->run();
        $this->travel(6)->minutes();
        $runner->run();

        $this->assertSame(0, MonitorAlert::count());
        $this->assertSame(1, MonitorIncident::whereNull('recovered_at')->first()->consecutive_failures);
    }

    public function test_timeout_and_rate_limit_reuse_request_id_then_poll_delivery(): void
    {
        $alert = $this->makeAlert();
        $submitted = [];
        Http::fake(function (Request $request) use (&$submitted) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'accepted'], 200);
            }

            $submitted[] = $request->data();

            return match (count($submitted)) {
                1 => throw new ConnectionException('timeout'),
                2 => Http::response(['message' => 'rate limited'], 429),
                default => Http::response(['id' => 'platform-job-1', 'status' => 'queued'], 200),
            };
        });

        $dispatcher = app(MonitorAlertDispatcher::class);
        $dispatcher->run();
        $this->travel(2)->minutes();
        $dispatcher->run();
        $this->travel(3)->minutes();
        $dispatcher->run();
        $this->assertSame([$alert->request_id, $alert->request_id, $alert->request_id], array_column($submitted, 'request_id'));
        $this->assertSame('20230001', $submitted[0]['recipients'][0]['id']);
        $this->assertSame('queued', $alert->fresh()->status);
        $this->travel(2)->minutes();
        $dispatcher->run();
        $this->assertSame('accepted', $alert->fresh()->status);
    }

    public function test_recovery_is_cancelled_when_outage_was_not_submitted(): void
    {
        $statuses = [503, 503, 200];
        Http::fake(function () use (&$statuses) {
            return Http::response('', array_shift($statuses));
        });

        $runner = app(MonitorRunner::class);
        $runner->run();
        $runner->run();
        $runner->run();

        $this->assertSame(4, MonitorAlert::where('status', 'cancelled')->count());
        Http::assertSentCount(3);
    }

    public function test_terminal_failure_and_retry_expiry_are_recorded(): void
    {
        $invalid = $this->makeAlert();
        Http::fake(fn () => Http::response(['message' => 'invalid'], 401));
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame('failed', $invalid->fresh()->status);

        $expired = $this->makeAlert('expired');
        $this->travel(25)->hours();
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame('failed', $expired->fresh()->status);
    }

    private function makeAlert(string $key = 'test'): MonitorAlert
    {
        $incident = MonitorIncident::create([
            'incident_key' => str_pad($key, 26, '0'),
            'service_key' => 'portal',
            'consecutive_failures' => 2,
            'started_at' => now(),
            'confirmed_at' => now(),
        ]);

        return $incident->alerts()->create([
            'kind' => 'outage',
            'request_id' => "campus_status:outage:{$incident->incident_key}_20230001",
            'status' => 'pending',
            'recipient_id' => '20230001',
            'recipient_email' => 'one@example.edu.cn',
            'title' => '故障',
            'content' => '服务故障',
            'next_attempt_at' => now(),
        ]);
    }
}
