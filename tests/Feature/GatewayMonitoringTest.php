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
use App\Services\MonitorAlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
