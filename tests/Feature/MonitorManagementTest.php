<?php

namespace Tests\Feature;

use App\Models\MonitorAlert;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorService;
use App\Models\User;
use App\Services\MonitorRunner;
use App\Services\MonitorTargetGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        config()->set('monitors.admin_emails', 'admin@example.edu.cn');

        return User::factory()->create(['email' => 'admin@example.edu.cn', 'email_verified_at' => now()]);
    }

    public function test_only_verified_allowlisted_users_can_manage_monitors(): void
    {
        $this->get('/admin/monitors')->assertRedirect('/login');

        $outsider = User::factory()->create(['email' => 'other@example.edu.cn']);
        $this->actingAs($outsider)->get('/admin/monitors')->assertForbidden();

        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->get('/admin/monitors')->assertRedirect();

        $admin->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($admin)->get('/admin/monitors')->assertOk();
    }

    public function test_admin_can_add_monitor_and_next_run_checks_it(): void
    {
        $this->actingAs($this->admin())->post('/admin/monitors', [
            'name' => '教务系统', 'description' => '课程与成绩', 'url' => 'http://10.2.3.4:8080/health',
        ])->assertRedirect('/admin/monitors');

        $service = MonitorService::where('name', '教务系统')->firstOrFail();
        $this->assertNull(MonitorCheck::where('service_key', $service->key)->first());

        Http::fake(['*' => Http::response('OK', 200)]);
        app(MonitorRunner::class)->run();
        $this->assertDatabaseHas('monitor_checks', ['service_key' => $service->key, 'is_up' => true]);
        $this->getJson('/api/status')->assertOk()->assertJsonFragment(['name' => '教务系统']);
    }

    public function test_url_rules_reject_unsafe_targets_and_duplicates(): void
    {
        $this->actingAs($this->admin());
        foreach (['http://127.0.0.1/', 'http://169.254.169.254/', 'file:///etc/passwd', 'http://other.example/', 'http://10.2.3.4:22/'] as $url) {
            $this->post('/admin/monitors', ['name' => '目标', 'url' => $url])->assertSessionHasErrors('url');
        }

        $this->post('/admin/monitors', ['name' => '重复', 'url' => 'https://www.zufedfc.edu.cn/'])
            ->assertSessionHasErrors('url');
    }

    public function test_url_change_and_reenable_start_fresh_cycles(): void
    {
        $this->actingAs($this->admin());
        $old = MonitorService::where('key', 'portal')->firstOrFail();
        MonitorCheck::create(['service_key' => $old->key, 'is_up' => false, 'checked_at' => now()]);

        $this->put('/admin/monitors/'.$old->id, [
            'name' => '学校官网', 'description' => '新版', 'url' => 'https://new.zufedfc.edu.cn/',
        ])->assertRedirect('/admin/monitors');

        $new = MonitorService::current()->where('name', '学校官网')->firstOrFail();
        $this->assertNotSame($old->key, $new->key);
        $this->assertNotNull($old->fresh()->archived_at);
        $this->assertSame(1, MonitorCheck::where('service_key', $old->key)->count());
        $this->getJson('/api/status')->assertJsonPath('services.0.state', 'unknown');

        $this->post('/admin/monitors/'.$new->id.'/disable')->assertRedirect('/admin/monitors');
        $this->assertFalse($new->fresh()->is_enabled);
        $this->post('/admin/monitors/'.$new->id.'/enable')->assertRedirect('/admin/monitors');
        $this->assertNotSame($new->key, MonitorService::current()->where('name', '学校官网')->firstOrFail()->key);
    }

    public function test_disable_closes_incident_and_cancels_only_unsubmitted_alert(): void
    {
        $this->actingAs($this->admin());
        $service = MonitorService::where('key', 'portal')->firstOrFail();
        $incident = MonitorIncident::create([
            'incident_key' => '01K9Q6TMTZY2R8A5V6E9N0W1XY',
            'service_key' => $service->key,
            'started_at' => now(),
            'confirmed_at' => now(),
        ]);
        $pending = $this->alert($incident, 'pending', 'pending');
        $submitted = $this->alert($incident, 'submitted', 'queued', 'job-1');

        $this->post('/admin/monitors/'.$service->id.'/disable')->assertRedirect('/admin/monitors');

        $this->assertSame('disabled', $incident->fresh()->closed_reason);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('queued', $submitted->fresh()->status);
    }

    public function test_redirect_host_policy_and_dns_rebinding_guard(): void
    {
        $guard = app(MonitorTargetGuard::class);
        $this->expectException(\InvalidArgumentException::class);
        $guard->normalize('https://login.example.net/', redirect: true);
    }

    public function test_probe_rejects_redirect_outside_allowed_hosts(): void
    {
        MonitorService::where('key', '!=', 'webvpn')->delete();
        MonitorService::where('key', 'webvpn')->update(['url' => 'http://10.2.3.4/', 'active_url_hash' => hash('sha256', 'http://10.2.3.4/')]);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        app(MonitorRunner::class)->run();

        $this->assertSame(1, MonitorCheck::count());
        $this->assertFalse(MonitorCheck::firstOrFail()->is_up);
        Http::assertSentCount(1);
    }

    public function test_dns_resolution_to_local_address_is_blocked(): void
    {
        $guard = new class extends MonitorTargetGuard
        {
            public function resolveAddresses(string $host): array
            {
                return ['169.254.169.254'];
            }
        };

        $this->expectException(\RuntimeException::class);
        $guard->curlOptions('https://www.zufedfc.edu.cn/');
    }

    private function alert(MonitorIncident $incident, string $suffix, string $status, ?string $taskId = null): MonitorAlert
    {
        return $incident->alerts()->create([
            'kind' => 'outage',
            'request_id' => 'campus_status:outage:'.$incident->incident_key.'_'.$suffix,
            'status' => $status,
            'platform_task_id' => $taskId,
            'recipient_id' => $suffix,
            'recipient_email' => $suffix.'@example.edu.cn',
            'title' => '故障',
            'content' => '测试',
            'next_attempt_at' => now(),
        ]);
    }
}
