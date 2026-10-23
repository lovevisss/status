<?php

namespace Tests\Feature;

use App\Models\MonitorAlert;
use App\Models\MonitorCheck;
use App\Models\MonitorRecipient;
use App\Models\User;
use App\Services\MonitorAlertDispatcher;
use App\Services\MonitorIncidentTracker;
use App\Services\MonitorRequestIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitorRecipientsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('monitors.alerts.api_url', 'https://messenger.zufedfc.edu.cn/api/v1/notifications');
        config()->set('monitors.alerts.api_token', 'test-token');
    }

    private function admin(): User
    {
        config()->set('monitors.admin_emails', 'admin@example.edu.cn');

        return User::factory()->create(['email' => 'admin@example.edu.cn', 'email_verified_at' => now()]);
    }

    public function test_only_verified_allowlisted_admins_can_manage_recipients(): void
    {
        $this->get('/admin/recipients')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/admin/recipients', ['person_id' => '10001'])->assertForbidden();

        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->get('/admin/recipients')->assertRedirect();

        $admin->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($admin)->get('/admin/recipients')->assertOk();
    }

    public function test_admin_can_add_edit_disable_and_enable_with_optional_email(): void
    {
        $this->actingAs($this->admin());
        $this->post('/admin/recipients', ['person_id' => '001234', 'email' => ''])
            ->assertRedirect('/admin/recipients');
        $recipient = MonitorRecipient::where('person_id', '001234')->firstOrFail();
        $this->assertNull($recipient->email);

        $this->post('/admin/recipients', ['person_id' => '001234'])->assertSessionHasErrors('person_id');
        $this->put('/admin/recipients/'.$recipient->id, [
            'person_id' => '001235', 'email' => 'person@example.edu.cn',
        ])->assertRedirect('/admin/recipients');
        $this->assertDatabaseHas('monitor_recipients', ['id' => $recipient->id, 'person_id' => '001235', 'email' => 'person@example.edu.cn']);

        $this->post('/admin/recipients/'.$recipient->id.'/disable')->assertRedirect('/admin/recipients');
        $this->assertFalse($recipient->fresh()->is_enabled);
        $this->post('/admin/recipients/'.$recipient->id.'/enable')->assertRedirect('/admin/recipients');
        $this->assertTrue($recipient->fresh()->is_enabled);
    }

    public function test_existing_incident_keeps_recipient_snapshot_after_list_changes(): void
    {
        $recipient = MonitorRecipient::create(['person_id' => '000001', 'email' => null, 'is_enabled' => true]);
        $service = ['key' => 'portal', 'name' => '学校官网', 'url' => 'https://www.zufedfc.edu.cn/'];
        $tracker = app(MonitorIncidentTracker::class);
        $record = function (bool $up) use ($tracker, $service): void {
            $check = MonitorCheck::create(['service_key' => 'portal', 'is_up' => $up, 'checked_at' => now()]);
            $tracker->record($service, $check);
        };

        $record(false);
        $record(false);
        $this->assertDatabaseHas('monitor_alerts', ['kind' => 'outage', 'recipient_id' => '000001', 'recipient_email' => null]);

        $recipient->update(['person_id' => '000002', 'email' => 'new@example.edu.cn', 'is_enabled' => false]);
        MonitorRecipient::create(['person_id' => '000003', 'email' => null, 'is_enabled' => true]);
        $record(true);
        $this->assertDatabaseHas('monitor_alerts', ['kind' => 'recovery', 'recipient_id' => '000001', 'recipient_email' => null]);

        $record(false);
        $record(false);
        $this->assertSame(1, MonitorAlert::where('kind', 'outage')->where('recipient_id', '000003')->count());
        $this->assertSame(0, MonitorAlert::where('kind', 'outage')->where('recipient_id', '000002')->count());
    }

    public function test_single_recipient_test_mail_is_queued_tracked_and_throttled(): void
    {
        $recipient = MonitorRecipient::create(['person_id' => '000001', 'email' => null, 'is_enabled' => true]);
        $this->actingAs($this->admin());

        $this->post('/admin/recipients/'.$recipient->id.'/test')->assertRedirect('/admin/recipients');
        $alert = $recipient->fresh()->lastTestAlert;
        $this->assertSame('pending', $alert->status);
        $this->assertSame('test', $alert->kind);
        $this->assertStringContainsString('【测试】', $alert->title);
        $this->post('/admin/recipients/'.$recipient->id.'/test')->assertSessionHasErrors('test');
        $this->assertSame(1, MonitorAlert::where('kind', 'test')->count());

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'accepted'], 200);
            }

            return Http::response(['id' => 'task-1', 'status' => 'queued'], 202);
        });
        app(MonitorAlertDispatcher::class)->run();
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->data()['recipients'] === [['id' => '000001']]);
        $this->assertSame('queued', $alert->fresh()->status);
        $this->assertSame('task-1', $alert->fresh()->platform_task_id);

        $this->travel(2)->minutes();
        app(MonitorAlertDispatcher::class)->run();
        $this->assertSame('accepted', $recipient->fresh()->lastTestAlert->status);
    }

    public function test_request_id_fits_platform_limit_for_long_person_id(): void
    {
        $id = MonitorRequestIds::forRecipient('recovery', str_repeat('A', 26), str_repeat('9', 120));
        $this->assertLessThanOrEqual(120, strlen($id));
        $this->assertSame($id, MonitorRequestIds::forRecipient('recovery', str_repeat('A', 26), str_repeat('9', 120)));
    }
}
