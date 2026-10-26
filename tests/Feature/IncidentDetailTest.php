<?php

namespace Tests\Feature;

use App\Models\MonitorIncident;
use App\Models\MonitorService;
use App\Services\MonitorServiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncidentDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Shanghai')->utc());
    }

    private function incident(array $changes = []): MonitorIncident
    {
        return MonitorIncident::create(array_merge([
            'incident_key' => '01K9Q6TMTZY2R8A5V6E9N0W1XY',
            'service_key' => 'portal',
            'consecutive_failures' => 2,
            'started_at' => Carbon::parse('2026-10-25 23:50:00', 'Asia/Shanghai')->utc(),
            'confirmed_at' => Carbon::parse('2026-10-25 23:52:00', 'Asia/Shanghai')->utc(),
        ], $changes));
    }

    public function test_recovered_incident_exposes_first_failure_confirmation_recovery_and_duration(): void
    {
        $incident = $this->incident([
            'recovered_at' => Carbon::parse('2026-10-26 01:20:00', 'Asia/Shanghai')->utc(),
            'closed_at' => Carbon::parse('2026-10-26 01:20:00', 'Asia/Shanghai')->utc(),
            'closed_reason' => 'recovered',
        ]);

        $this->get('/incidents/'.$incident->incident_key)->assertOk()->assertInertia(fn ($page) => $page
            ->component('IncidentDetail')
            ->where('incident.service_name', '学校官网')
            ->where('incident.status', 'recovered')
            ->where('incident.duration_seconds', 5400)
            ->where('incident.started_at', '2026-10-25T15:50:00+00:00')
            ->where('incident.confirmed_at', '2026-10-25T15:52:00+00:00')
            ->where('incident.recovered_at', '2026-10-25T17:20:00+00:00'));
    }

    public function test_ongoing_incident_has_no_recovery_time_and_duration_reaches_current_time(): void
    {
        $incident = $this->incident();

        $this->get('/incidents/'.$incident->incident_key)->assertOk()->assertInertia(fn ($page) => $page
            ->where('incident.status', 'ongoing')
            ->where('incident.recovered_at', null)
            ->where('incident.duration_seconds', 43800));
    }

    public function test_disabled_service_closes_incident_without_claiming_recovery(): void
    {
        $incident = $this->incident();
        $service = MonitorService::where('key', 'portal')->firstOrFail();
        app(MonitorServiceManager::class)->disable($service);

        $this->get('/incidents/'.$incident->incident_key)->assertOk()->assertInertia(fn ($page) => $page
            ->where('incident.status', 'closed')
            ->where('incident.recovered_at', null)
            ->where('incident.duration_seconds', 43800));
        $this->assertNotNull($incident->fresh()->closed_at);
    }

    public function test_archived_service_incident_remains_available_by_direct_link(): void
    {
        $incident = $this->incident();
        $service = MonitorService::where('key', 'portal')->firstOrFail();
        app(MonitorServiceManager::class)->update($service, '学校官网', '', 'https://new.zufedfc.edu.cn/');

        $this->get('/incidents/'.$incident->incident_key)->assertOk()->assertInertia(fn ($page) => $page
            ->where('incident.status', 'closed')
            ->where('incident.service_url', $service->url));
    }

    public function test_unconfirmed_test_and_unknown_incidents_are_not_public(): void
    {
        $unconfirmed = $this->incident(['confirmed_at' => null]);
        $this->get('/incidents/'.$unconfirmed->incident_key)->assertNotFound();

        $unconfirmed->update(['confirmed_at' => now(), 'closed_reason' => 'test']);
        $this->get('/incidents/'.$unconfirmed->incident_key)->assertNotFound();
        $this->get('/incidents/not-a-real-event')->assertNotFound();
    }
}
