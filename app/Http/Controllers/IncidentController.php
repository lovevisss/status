<?php

namespace App\Http\Controllers;

use App\Models\MonitorIncident;
use App\Models\MonitorService;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function show(string $key): Response
    {
        $incident = MonitorIncident::where('incident_key', $key)
            ->whereNotNull('confirmed_at')
            ->where(fn ($query) => $query->whereNull('closed_reason')->orWhere('closed_reason', '!=', 'test'))
            ->firstOrFail();
        $service = MonitorService::where('key', $incident->service_key)->firstOrFail();
        $status = $incident->recovered_at ? 'recovered' : ($incident->closed_at ? 'closed' : 'ongoing');
        $lastAt = $incident->recovered_at ?? $incident->closed_at ?? now();

        return Inertia::render('IncidentDetail', [
            'incident' => [
                'key' => $incident->incident_key,
                'service_name' => $service->name,
                'service_url' => $service->url,
                'started_at' => $incident->started_at->toIso8601String(),
                'confirmed_at' => $incident->confirmed_at->toIso8601String(),
                'recovered_at' => $incident->recovered_at?->toIso8601String(),
                'closed_at' => $incident->closed_at?->toIso8601String(),
                'status' => $status,
                'duration_seconds' => max(0, (int) $incident->started_at->diffInSeconds($lastAt)),
            ],
        ]);
    }
}
