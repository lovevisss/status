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
        $service = MonitorService::where('key', $incident->service_key)->first();
        if (! $service && ! str_starts_with($incident->service_key, 'gateway-')) {
            abort(404);
        }
        $status = $incident->recovered_at ? 'recovered' : ($incident->closed_at ? 'closed' : 'ongoing');
        $lastAt = $incident->recovered_at ?? $incident->closed_at ?? now();

        return Inertia::render('IncidentDetail', [
            'incident' => [
                'key' => $incident->incident_key,
                'service_name' => $incident->target_name ?? $service?->name,
                'service_url' => $incident->target_address ?? $service?->url,
                'is_gateway' => str_starts_with($incident->service_key, 'gateway-'),
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
