<?php

namespace Tests\Feature;

use App\Models\GatewayMonitor;
use App\Models\GatewayRecipient;
use App\Models\MonitorAlert;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorRecipient;
use App\Models\User;
use App\Services\GatewayPing;
use App\Services\GatewayRunner;
use App\Services\GatewayAlertBatcher;
use App\Services\MonitorAlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GatewayMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('monitors.alerts.api_token', 'shared-token');
    }

    private function admin(): User
    {
        config()->set('monitors.admin_emails', 'admin@example.edu.cn');

        return User::factory()->create(['email' => 'admin@example.edu.cn', 'email_verified_at' => now()]);
    }

    private function confirmedGateway(int $building): MonitorIncident
    {
        $gateway = GatewayMonitor::where('building', $building)->firstOrFail();
        MonitorCheck::create([
            'service_key' => $gateway->key, 'is_up' => false, 'response_ms' => 10,
            'error' => 'Ping 无响应', 'checked_at' => now(),
        ]);

        return MonitorIncident::create([
            'incident_key' => (string) Str::ulid(), 'service_key' => $gateway->key,
            'target_name' => $gateway->gateway_number.' · '.$gateway->location,
            'target_address' => $gateway->ip,
            'started_at' => now()->subMinutes(2), 'confirmed_at' => now(),
            'consecutive_failures' => 2,
        ]);
    }

    public function test_three_gateways_make_one_batch_per_channel_and_recover_only_when_all_recovered(): void
    {
        config()->set('monitors.gateway_sms.batch_enabled', true);
        GatewayRecipient::create([
            'person_id' => '001234', 'email' => 'operator@example.edu.cn', 'notify_email' => true,
            'notify_sms' => true, 'is_enabled' => true,
        ]);
        $first = $this->confirmedGateway(1);
        $second = $this->confirmedGateway(2);
        $this->travel(4)->minutes();
        $third = $this->confirmedGateway(3);
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(0, MonitorAlert::count());

        $this->travel(1)->minutes();
        app(GatewayAlertBatcher::class)->run();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(1, DB::table('gateway_alert_batches')->count());
        $this->assertSame(3, DB::table('gateway_alert_batch_members')->count());
        $this->assertSame(2, MonitorAlert::where('kind', 'outage')->count());
        $this->assertSame('3台宿舍电表网关故障，请查看状态页。', MonitorAlert::where('channel', 'sms')->firstOrFail()->content);
        $this->assertStringContainsString('10.4.90.13', MonitorAlert::where('channel', 'email')->firstOrFail()->content);

        Http::fake(['*' => Http::response(['id' => 'task-1', 'status' => 'accepted'], 202)]);
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame(2, MonitorAlert::where('status', 'accepted')->count());
        GatewayRecipient::where('person_id', '001234')->update(['is_enabled' => false]);
        foreach ([$first, $second] as $incident) {
            $incident->update(['closed_at' => now(), 'recovered_at' => now(), 'closed_reason' => 'recovered']);
        }
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(0, MonitorAlert::where('kind', 'recovery')->count());
        $third->update(['closed_at' => now(), 'recovered_at' => now(), 'closed_reason' => 'recovered']);
        app(GatewayAlertBatcher::class)->run();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(2, MonitorAlert::where('kind', 'recovery')->count());
        $this->assertSame('3台宿舍电表网关已全部恢复。', MonitorAlert::where('kind', 'recovery')->where('channel', 'sms')->firstOrFail()->content);
        $this->assertSame(['001234', '001234'], MonitorAlert::where('kind', 'recovery')->orderBy('id')->pluck('recipient_id')->all());
        $this->actingAs($this->admin())->get('/admin/gateway-recipients')->assertInertia(fn ($page) => $page
            ->where('recentAlerts.0.batch_members.2', '202500832 · 3号楼3楼电表间：10.4.90.13')->etc());
    }

    public function test_two_gateways_send_individual_alerts_after_window_and_early_recovery_is_silent(): void
    {
        GatewayRecipient::create(['person_id' => '001234', 'notify_email' => true, 'is_enabled' => true]);
        $first = $this->confirmedGateway(1);
        $this->confirmedGateway(2);
        $this->travel(2)->minutes();
        $first->update(['closed_at' => now(), 'recovered_at' => now(), 'closed_reason' => 'recovered']);
        $this->travel(3)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(1, MonitorAlert::where('kind', 'outage')->count());
        $this->assertSame(0, MonitorAlert::where('kind', 'recovery')->count());
        $this->assertSame(0, DB::table('gateway_alert_batches')->count());
    }

    public function test_two_active_gateways_remain_individual_and_later_outage_starts_new_window(): void
    {
        GatewayRecipient::create(['person_id' => '001234', 'notify_email' => true, 'is_enabled' => true]);
        $this->confirmedGateway(1);
        $this->confirmedGateway(2);
        $this->travel(5)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(2, MonitorAlert::where('kind', 'outage')->count());
        $this->confirmedGateway(3);
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(2, MonitorAlert::count());
        $this->travel(5)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(3, MonitorAlert::where('kind', 'outage')->count());
        $this->assertSame(0, DB::table('gateway_alert_batches')->count());
    }

    public function test_group_sms_uses_final_utf16_length_limit(): void
    {
        GatewayRecipient::create(['person_id' => '001234', 'notify_sms' => true, 'is_enabled' => true]);
        config()->set('monitors.gateway_sms.batch_enabled', true);
        config()->set('monitors.gateway_sms.batch_outage_template', str_repeat('😀', 35));
        foreach ([1, 2, 3] as $building) {
            $this->confirmedGateway($building);
        }
        $this->travel(5)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertDatabaseHas('monitor_alerts', ['kind' => 'outage', 'channel' => 'sms', 'status' => 'pending']);
        Http::fake(['*' => Http::response(['id' => 'task-1', 'status' => 'accepted'], 202)]);
        app(MonitorAlertDispatcher::class)->run();

        config()->set('monitors.gateway_sms.batch_recovery_template', str_repeat('😀', 36));
        foreach (MonitorIncident::where('service_key', 'like', 'gateway-%')->get() as $incident) {
            $incident->update(['closed_at' => now(), 'recovered_at' => now(), 'closed_reason' => 'recovered']);
        }
        app(GatewayAlertBatcher::class)->run();
        $this->assertDatabaseHas('monitor_alerts', [
            'kind' => 'recovery', 'channel' => 'sms', 'status' => 'configuration_error',
            'last_error' => '短信正文超过70个UTF-16码元',
        ]);
    }

    public function test_preexisting_decided_incident_is_not_requeued(): void
    {
        GatewayRecipient::create(['person_id' => '001234', 'notify_email' => true, 'is_enabled' => true]);
        $old = $this->confirmedGateway(1);
        $old->update(['notification_decided_at' => now()]);
        $this->travel(6)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(0, MonitorAlert::count());
    }

    public function test_gateway_runner_queues_only_group_recovery_for_batched_incidents(): void
    {
        GatewayMonitor::where('building', '>', 3)->update(['is_enabled' => false]);
        GatewayRecipient::create(['person_id' => '001234', 'notify_email' => true, 'is_enabled' => true]);
        $ping = $this->mock(GatewayPing::class);
        $ping->shouldReceive('probe')->times(3)->andReturnUsing(function () {
            static $run = 0;
            $run++;
            $up = $run === 3;

            return GatewayMonitor::where('is_enabled', true)->get()->mapWithKeys(fn (GatewayMonitor $gateway) => [
                $gateway->ip => ['up' => $up, 'response_ms' => 10, 'error' => $up ? null : 'Ping 无响应'],
            ])->all();
        });
        $runner = app(GatewayRunner::class);
        $runner->run();
        $runner->run();
        $this->travel(5)->minutes();
        app(GatewayAlertBatcher::class)->run();
        Http::fake(['*' => Http::response(['id' => 'task-1', 'status' => 'accepted'], 202)]);
        app(MonitorAlertDispatcher::class)->run();
        $runner->run();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(2, MonitorAlert::count());
        $this->assertSame(1, MonitorAlert::where('kind', 'recovery')->count());
        $this->assertSame('__gateway_batch__', MonitorAlert::where('kind', 'recovery')->firstOrFail()->incident->service_key);
    }

    public function test_sixteen_gateway_batch_sms_stays_blocked_until_approval_and_ip_change_prevents_group_recovery(): void
    {
        GatewayRecipient::create(['person_id' => '001234', 'notify_email' => true, 'notify_sms' => true, 'is_enabled' => true]);
        $incidents = [];
        for ($building = 1; $building <= 16; $building++) {
            $incidents[] = $this->confirmedGateway($building);
        }
        $this->travel(5)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(16, DB::table('gateway_alert_batch_members')->count());
        $this->assertSame(2, MonitorAlert::where('kind', 'outage')->count());
        $this->assertDatabaseHas('monitor_alerts', [
            'channel' => 'sms', 'status' => 'configuration_error',
            'last_error' => '批量短信文案尚未通过MAS审批',
        ]);
        $this->assertSame('16台宿舍电表网关故障，请查看状态页。', MonitorAlert::where('channel', 'sms')->firstOrFail()->content);
        foreach ($incidents as $index => $incident) {
            $incident->update([
                'closed_at' => now(), 'recovered_at' => $index === 0 ? null : now(),
                'closed_reason' => $index === 0 ? 'target_changed' : 'recovered',
            ]);
        }
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(0, MonitorAlert::where('kind', 'recovery')->count());
    }

    public function test_seeded_gateway_list_and_public_status_are_separate_from_websites(): void
    {
        $this->assertSame(16, GatewayMonitor::count());
        $this->assertDatabaseHas('gateway_monitors', [
            'gateway_number' => '202500830', 'ip' => '10.4.90.11', 'location' => '1号楼3楼电表间',
        ]);
        $this->assertDatabaseHas('gateway_monitors', [
            'gateway_number' => '202500845', 'ip' => '10.100.36.3', 'location' => '16号楼3楼电表间',
        ]);
        $this->getJson('/api/status')->assertOk()->assertJsonCount(16, 'gateways')
            ->assertJsonCount(3, 'services')->assertJsonPath('gateways.0.state', 'unknown');
    }

    public function test_gateway_probe_routes_email_and_sms_only_to_gateway_group_and_recovers(): void
    {
        GatewayMonitor::where('gateway_number', '!=', '202500830')->update(['is_enabled' => false]);
        $gateway = GatewayMonitor::where('gateway_number', '202500830')->firstOrFail();
        MonitorRecipient::create(['person_id' => 'website-only', 'is_enabled' => true]);
        GatewayRecipient::create([
            'person_id' => '001234', 'email' => 'operator@example.edu.cn', 'mobile' => '13800138000',
            'notify_email' => true, 'notify_sms' => true, 'is_enabled' => true,
        ]);
        $outcomes = [false, false, true];
        $ping = $this->mock(GatewayPing::class);
        $ping->shouldReceive('probe')->times(3)->andReturnUsing(function () use (&$outcomes, $gateway) {
            $up = array_shift($outcomes);

            return [$gateway->ip => ['up' => $up, 'response_ms' => 10, 'error' => $up ? null : 'Ping 无响应']];
        });

        $runner = app(GatewayRunner::class);
        $runner->run();
        $this->assertSame(0, MonitorAlert::count());
        $runner->run();
        $this->travel(6)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertSame(2, MonitorAlert::where('kind', 'outage')->count());
        $this->assertSame(['email', 'sms'], MonitorAlert::where('kind', 'outage')->orderBy('id')->pluck('channel')->all());
        $this->assertDatabaseMissing('monitor_alerts', ['recipient_id' => 'website-only']);

        Http::fake(function (Request $request) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer shared-token'));
            $sms = $request->data()['channels'] === ['sms'];
            $this->assertSame('001234', $request->data()['recipients'][0]['id']);
            if ($sms) {
                $this->assertSame('13800138000', $request->data()['recipients'][0]['mobile']);
                $this->assertContains($request->data()['content'], [
                    '10.4.90.11 1号楼3楼电表间的设备故障，请及时关注',
                    '10.4.90.11 1号楼3楼电表间的设备故障已经恢复',
                ]);
            } else {
                $this->assertSame('operator@example.edu.cn', $request->data()['recipients'][0]['email']);
            }

            return Http::response(['id' => 'task-'. $request->data()['channels'][0], 'status' => 'accepted'], 202);
        });
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame(2, MonitorAlert::where('status', 'accepted')->count());
        $runner->run();
        $this->assertSame(2, MonitorAlert::where('kind', 'recovery')->count());
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame(4, MonitorAlert::where('status', 'accepted')->count());
        $this->assertNotNull(MonitorIncident::where('service_key', $gateway->key)->firstOrFail()->recovered_at);
        $this->getJson('/api/status')->assertJsonPath('gateways.0.state', 'up');
        $this->assertSame(3, MonitorCheck::where('service_key', $gateway->key)->count());
    }

    public function test_admin_can_edit_disable_gateway_and_manage_exclusive_recipients(): void
    {
        $this->get('/admin/gateways')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/gateway-recipients')->assertForbidden();
        $this->actingAs($this->admin());
        $gateway = GatewayMonitor::firstOrFail();
        $this->put('/admin/gateways/'.$gateway->id, [
            'gateway_number' => $gateway->gateway_number, 'ip' => '127.0.0.1',
            'building' => $gateway->building, 'location' => $gateway->location,
        ])->assertSessionHasErrors('ip');
        $this->put('/admin/gateways/'.$gateway->id, [
            'gateway_number' => $gateway->gateway_number, 'ip' => '10.4.91.11',
            'building' => $gateway->building, 'location' => $gateway->location,
        ])->assertRedirect('/admin/gateways');
        $this->assertNotSame($gateway->key, $gateway->fresh()->key);
        $this->post('/admin/gateways/'.$gateway->id.'/disable')->assertRedirect('/admin/gateways');
        $this->assertFalse($gateway->fresh()->is_enabled);

        $this->post('/admin/gateway-recipients', [
            'person_id' => '001234', 'email' => '', 'mobile' => '',
            'notify_email' => false, 'notify_sms' => true,
        ])->assertRedirect('/admin/gateway-recipients');
        $this->assertDatabaseHas('gateway_recipients', ['person_id' => '001234', 'notify_sms' => true]);
    }

    public function test_missing_shared_token_records_sms_configuration_error(): void
    {
        GatewayMonitor::where('gateway_number', '!=', '202500830')->update(['is_enabled' => false]);
        GatewayRecipient::create(['person_id' => '001234', 'notify_sms' => true, 'is_enabled' => true]);
        config()->set('monitors.alerts.api_token', null);
        $gateway = GatewayMonitor::where('gateway_number', '202500830')->firstOrFail();
        $ping = $this->mock(GatewayPing::class);
        $ping->shouldReceive('probe')->twice()->andReturn([$gateway->ip => ['up' => false, 'response_ms' => 10, 'error' => 'Ping 无响应']]);
        app(GatewayRunner::class)->run();
        app(GatewayRunner::class)->run();
        $this->travel(6)->minutes();
        app(GatewayAlertBatcher::class)->run();
        $this->assertDatabaseHas('monitor_alerts', ['channel' => 'sms', 'status' => 'configuration_error']);
    }

    public function test_gateway_sms_test_uses_first_enabled_gateway_and_requires_one(): void
    {
        $this->actingAs($this->admin());
        $person = GatewayRecipient::create(['person_id' => '001234', 'notify_sms' => true, 'is_enabled' => true]);
        $this->post('/admin/gateway-recipients/'.$person->id.'/test')->assertRedirect('/admin/gateway-recipients');
        $this->assertSame('10.4.90.11 1号楼3楼电表间的设备  这是测试', MonitorAlert::firstOrFail()->content);

        GatewayMonitor::query()->update(['is_enabled' => false]);
        $this->travel(6)->minutes();
        $this->post('/admin/gateway-recipients/'.$person->id.'/test')->assertSessionHasErrors('test');
        $this->assertSame(1, MonitorAlert::count());
    }

    public function test_422_field_error_and_sent_content_are_visible_to_gateway_admin(): void
    {
        $this->actingAs($this->admin());
        $person = GatewayRecipient::create(['person_id' => '001234', 'notify_sms' => true, 'is_enabled' => true]);
        $this->post('/admin/gateway-recipients/'.$person->id.'/test')->assertRedirect('/admin/gateway-recipients');
        Http::fake(['*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['channels.0' => ['该渠道不可用']],
        ], 422)]);
        app(MonitorAlertDispatcher::class)->run();

        $alert = MonitorAlert::firstOrFail()->fresh();
        $this->assertSame('failed', $alert->status);
        $this->assertStringContainsString('channels.0：该渠道不可用', $alert->last_error);
        $this->get('/admin/gateway-recipients')->assertInertia(fn ($page) => $page
            ->where('recentAlerts.0.content', '10.4.90.11 1号楼3楼电表间的设备  这是测试')
            ->where('recentAlerts.0.last_error', $alert->last_error)
            ->etc());
    }

    public function test_ping_program_failure_does_not_open_gateway_incidents(): void
    {
        GatewayMonitor::where('gateway_number', '!=', '202500830')->update(['is_enabled' => false]);
        $gateway = GatewayMonitor::where('gateway_number', '202500830')->firstOrFail();
        $ping = $this->mock(GatewayPing::class);
        $ping->shouldReceive('probe')->once()->andReturn([
            $gateway->ip => ['up' => false, 'response_ms' => 0, 'error' => 'Ping 命令无法启动', 'valid' => false],
        ]);
        app(GatewayRunner::class)->run();

        $this->assertSame(0, MonitorCheck::where('service_key', $gateway->key)->count());
        $this->assertSame(0, MonitorIncident::where('service_key', $gateway->key)->count());
        $this->getJson('/api/status')->assertJsonPath('gateways.0.state', 'unknown');
    }
}
