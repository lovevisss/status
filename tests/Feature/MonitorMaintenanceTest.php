<?php

namespace Tests\Feature;

use App\Models\MonitorAlert;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorMaintenanceWindow;
use App\Models\MonitorRecipient;
use App\Models\MonitorService;
use App\Models\User;
use App\Services\MonitorAlertDispatcher;
use App\Services\MonitorIncidentTracker;
use App\Services\MonitorRunner;
use App\Services\MonitorServiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitorMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Asia/Shanghai')->utc());
    }

    private function portal(): MonitorService
    {
        return MonitorService::where('key', 'portal')->firstOrFail();
    }

    private function admin(): User
    {
        config()->set('monitors.admin_emails', 'admin@example.edu.cn');

        return User::factory()->create(['email' => 'admin@example.edu.cn', 'email_verified_at' => now()]);
    }

    private function window(array $serviceIds, int $startDays = 1, int $hours = 2): MonitorMaintenanceWindow
    {
        $window = MonitorMaintenanceWindow::create([
            'title' => '网络设备升级',
            'description' => '维护期间可能无法访问。',
            'starts_at' => now()->addDays($startDays),
            'ends_at' => now()->addDays($startDays)->addHours($hours),
        ]);
        $window->services()->attach($serviceIds);

        return $window;
    }

    public function test_admin_access_schedule_conflict_and_cancel(): void
    {
        $portal = $this->portal();
        $party = MonitorService::where('key', 'party')->firstOrFail();
        $payload = [
            'title' => '网络设备升级', 'description' => '短时中断',
            'services' => [$portal->id, $party->id],
            'starts_at' => '2026-10-27T10:00', 'ends_at' => '2026-10-27T12:00',
        ];
        $this->post('/admin/maintenance', $payload)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/admin/maintenance', $payload)->assertForbidden();
        $this->actingAs($this->admin())->post('/admin/maintenance', $payload)->assertRedirect('/admin/maintenance');

        $window = MonitorMaintenanceWindow::firstOrFail();
        $this->assertSame(2, $window->services()->count());
        $this->assertSame('2026-10-27 02:00:00', $window->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->post('/admin/maintenance', [...$payload, 'services' => [$portal->id]])->assertSessionHasErrors('services');
        $this->post('/admin/maintenance/'.$window->id.'/cancel')->assertRedirect('/admin/maintenance');
        $this->assertNotNull($window->fresh()->cancelled_at);
        $this->post('/admin/maintenance', $payload)->assertRedirect('/admin/maintenance');
    }

    public function test_public_notice_appears_seven_days_before_and_service_version_does_not_inherit(): void
    {
        $portal = $this->portal();
        $window = $this->window([$portal->id], 8);
        $this->getJson('/api/status')->assertOk()->assertJsonCount(0, 'maintenance');
        $this->travel(1)->day();
        $this->getJson('/api/status')->assertOk()->assertJsonCount(1, 'maintenance');

        app(MonitorServiceManager::class)->update($portal, '学校官网', '', 'https://new.zufedfc.edu.cn/');
        $this->getJson('/api/status')->assertOk()->assertJsonCount(0, 'maintenance');
        $this->actingAs($this->admin())->get('/admin/maintenance')->assertOk()
            ->assertInertia(fn ($page) => $page->where('windows.0.needs_attention', true));
        $this->assertSame(1, $window->services()->count());

        $replacement = MonitorService::enabled()->where('name', '学校官网')->firstOrFail();
        $this->put('/admin/maintenance/'.$window->id, [
            'title' => '网络设备升级', 'description' => '维护期间可能无法访问。',
            'services' => [$replacement->id],
            'starts_at' => '2026-11-03T10:00', 'ends_at' => '2026-11-03T12:00',
        ])->assertRedirect('/admin/maintenance');
        $this->getJson('/api/status')->assertJsonCount(1, 'maintenance');
    }

    public function test_maintenance_checks_are_excluded_from_uptime_and_failures_restart_after_end(): void
    {
        MonitorService::where('key', '!=', 'portal')->update(['is_enabled' => false, 'active_url_hash' => null]);
        $portal = $this->portal();
        MonitorRecipient::create(['person_id' => '000001', 'is_enabled' => true]);
        $window = MonitorMaintenanceWindow::create([
            'title' => '学校官网维护', 'description' => '计划升级',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes(10),
        ]);
        $window->services()->attach($portal->id);
        MonitorCheck::create(['service_key' => 'portal', 'is_up' => true, 'checked_at' => now()->subMinutes(2)]);
        Http::fake(fn () => Http::response('', 503));

        $runner = app(MonitorRunner::class);
        $runner->run();
        $runner->run();
        $this->assertSame(0, MonitorIncident::whereNotNull('confirmed_at')->count());
        $this->assertSame(2, MonitorCheck::where('is_maintenance', true)->count());
        $this->getJson('/api/status')->assertOk()
            ->assertJsonPath('services.0.state', 'maintenance')
            ->assertJsonPath('services.0.uptime', 100)
            ->assertJsonPath('maintenance.0.status', 'active');

        $this->travel(11)->minutes();
        $runner->run();
        $this->assertSame(0, MonitorAlert::count());
        $runner->run();
        $this->assertSame(1, MonitorAlert::where('kind', 'outage')->count());
        $this->assertSame(2, MonitorIncident::whereNotNull('confirmed_at')->firstOrFail()->consecutive_failures);
    }

    public function test_existing_outage_recovers_during_maintenance_and_queues_recovery(): void
    {
        $portal = $this->portal();
        MonitorRecipient::create(['person_id' => '000001', 'is_enabled' => true]);
        $incident = MonitorIncident::create([
            'incident_key' => '01K9Q6TMTZY2R8A5V6E9N0W1XY', 'service_key' => 'portal',
            'consecutive_failures' => 2, 'started_at' => now()->subMinutes(5), 'confirmed_at' => now()->subMinutes(3),
        ]);
        $incident->alerts()->create([
            'kind' => 'outage', 'request_id' => 'campus_status:outage:existing_000001',
            'status' => 'accepted', 'recipient_id' => '000001', 'title' => '故障',
            'content' => '故障', 'platform_task_id' => 'task-1',
        ]);
        $window = $this->window([$portal->id], 0);
        $window->update(['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $check = MonitorCheck::create(['service_key' => 'portal', 'is_up' => true, 'is_maintenance' => true, 'checked_at' => now()]);
        app(MonitorIncidentTracker::class)->record($portal->only(['key', 'name', 'url']), $check);
        $this->assertDatabaseHas('monitor_alerts', ['kind' => 'recovery', 'recipient_id' => '000001', 'status' => 'pending']);
        $this->assertNotNull($incident->fresh()->recovered_at);
    }

    public function test_active_window_can_extend_then_finish_and_remains_in_recent_history(): void
    {
        $portal = $this->portal();
        $window = $this->window([$portal->id], 0);
        $window->update(['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $this->actingAs($this->admin())->put('/admin/maintenance/'.$window->id, [
            'ends_at' => '2026-10-26T13:00',
        ])->assertRedirect('/admin/maintenance');
        $this->assertSame('2026-10-26 05:00:00', $window->fresh()->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->post('/admin/maintenance/'.$window->id.'/finish')->assertRedirect('/admin/maintenance');
        $this->getJson('/api/status')->assertJsonPath('maintenance.0.status', 'completed');
        $this->travel(31)->days();
        $this->getJson('/api/status')->assertJsonCount(0, 'maintenance');
    }

    public function test_pending_failure_before_maintenance_does_not_count_after_it(): void
    {
        $portal = $this->portal();
        MonitorRecipient::create(['person_id' => '000001', 'is_enabled' => true]);
        $tracker = app(MonitorIncidentTracker::class);
        $service = $portal->only(['key', 'name', 'url']);
        foreach ([false, true, false, false] as $maintenance) {
            $check = MonitorCheck::create([
                'service_key' => 'portal', 'is_up' => false,
                'is_maintenance' => $maintenance, 'checked_at' => now(),
            ]);
            $tracker->record($service, $check);
        }
        $this->assertSame(1, MonitorAlert::where('kind', 'outage')->count());
        $this->assertSame(2, MonitorIncident::whereNotNull('confirmed_at')->firstOrFail()->consecutive_failures);
    }

    public function test_short_window_without_a_probe_still_resets_pending_failure(): void
    {
        $portal = $this->portal();
        MonitorRecipient::create(['person_id' => '000001', 'is_enabled' => true]);
        $tracker = app(MonitorIncidentTracker::class);
        $service = $portal->only(['key', 'name', 'url']);
        $first = MonitorCheck::create(['service_key' => 'portal', 'is_up' => false, 'checked_at' => now()->subMinutes(3)]);
        $tracker->record($service, $first);
        $window = MonitorMaintenanceWindow::create([
            'title' => '短暂维护', 'description' => '交换机重启',
            'starts_at' => now()->subMinutes(2), 'ends_at' => now()->subMinute(),
        ]);
        $window->services()->attach($portal->id);
        $second = MonitorCheck::create(['service_key' => 'portal', 'is_up' => false, 'checked_at' => now()]);
        $tracker->record($service, $second);
        $this->assertSame(0, MonitorAlert::count());
        $this->assertSame(1, MonitorIncident::whereNull('closed_at')->firstOrFail()->consecutive_failures);
    }

    public function test_preexisting_confirmed_outage_remains_visible_and_its_email_is_dispatched(): void
    {
        $portal = $this->portal();
        $window = $this->window([$portal->id], 0);
        $window->update(['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $incident = MonitorIncident::create([
            'incident_key' => '01K9Q6TMTZY2R8A5V6E9N0W1XZ', 'service_key' => 'portal',
            'consecutive_failures' => 2, 'started_at' => now()->subMinutes(5), 'confirmed_at' => now()->subMinutes(3),
        ]);
        $alert = $incident->alerts()->create([
            'kind' => 'outage', 'request_id' => 'campus_status:outage:existing_000002',
            'status' => 'pending', 'recipient_id' => '000002', 'title' => '故障',
            'content' => '故障', 'next_attempt_at' => now(),
        ]);
        MonitorCheck::create(['service_key' => 'portal', 'is_up' => false, 'is_maintenance' => true, 'checked_at' => now()]);
        $this->getJson('/api/status')->assertJsonPath('services.0.state', 'down')
            ->assertJsonPath('services.0.maintenance', true);

        config()->set('monitors.alerts.api_url', 'https://messenger.zufedfc.edu.cn/api/v1/notifications');
        config()->set('monitors.alerts.api_token', 'test-token');
        Http::fake(fn () => Http::response(['id' => 'existing-task', 'status' => 'accepted'], 202));
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame('accepted', $alert->fresh()->status);
    }
}
