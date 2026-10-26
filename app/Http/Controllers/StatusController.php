<?php

namespace App\Http\Controllers;

use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\MonitorMaintenanceWindow;
use App\Models\MonitorService;
use App\Services\MonitorAdminAccess;
use App\Services\MonitorServiceStats;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class StatusController extends Controller
{
    public function index(MonitorAdminAccess $access): Response
    {
        return Inertia::render('Welcome', [
            ...$this->data(),
            'canManage' => $access->allows(request()->user()),
        ]);
    }

    public function show(): JsonResponse
    {
        return response()->json($this->data());
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $services = MonitorService::enabled()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MonitorService $service) => $service->only(['id', 'key', 'name', 'description', 'url']))
            ->all();
        $currentById = collect($services)->keyBy('id');
        $maintenance = MonitorMaintenanceWindow::with('services:id,name')
            ->whereNull('cancelled_at')->where('starts_at', '<=', now()->addDays(7))
            ->where('ends_at', '>=', now()->subDays(30))->orderBy('starts_at')->get()
            ->map(function (MonitorMaintenanceWindow $window) use ($currentById) {
                $affected = $window->services->filter(fn (MonitorService $service) => $currentById->has($service->id));
                $end = $window->effectiveEnd();
                if ($affected->isEmpty() || $end->lt(now()->subDays(30))) {
                    return null;
                }

                return [
                    'id' => $window->id,
                    'title' => $window->title,
                    'description' => $window->description,
                    'starts_at' => $window->starts_at->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'status' => $window->starts_at->gt(now()) ? 'planned' : ($end->gt(now()) ? 'active' : 'completed'),
                    'service_ids' => $affected->pluck('id')->all(),
                    'service_names' => $affected->pluck('name')->all(),
                ];
            })->filter()->values()->all();
        /** @var array<string, MonitorServiceStats> $stats */
        $stats = [];
        foreach ($services as $service) {
            $stats[$service['key']] = new MonitorServiceStats;
        }

        foreach (MonitorCheck::where('checked_at', '>=', now()->subDays(30))
            ->orderBy('service_key')->orderBy('checked_at')->cursor() as $check) {
            if (! isset($stats[$check->service_key])) {
                continue;
            }

            $stats[$check->service_key]->add($check);
        }

        $openIncidents = MonitorIncident::whereNull('closed_at')->latest('id')->get()
            ->unique('service_key')->keyBy('service_key');
        $items = [];
        foreach ($services as $service) {
            $entry = $stats[$service['key']];
            $latest = $entry->latest;
            $stale = ! $latest || $latest->checked_at->lt(now()->subMinutes(5));
            $incident = $openIncidents->get($service['key']);
            $inMaintenance = collect($maintenance)->contains(fn (array $window) => $window['status'] === 'active' && in_array($service['id'], $window['service_ids'], true));
            $state = $stale ? 'unknown' : ($incident?->confirmed_at && ! $latest->is_up ? 'down' : ($inMaintenance ? 'maintenance' : ($latest->is_up ? 'up' : 'pending')));
            $days = [];
            foreach ($entry->days as $date => $samples) {
                $days[$date] = [
                    'state' => $samples['measured'] === 0 ? 'maintenance' : ($samples['up'] === $samples['measured'] ? 'up' : ($samples['up'] ? 'degraded' : 'down')),
                    'count' => $samples['count'],
                    'maintenance' => $samples['maintenance'] > 0,
                ];
            }

            $expected = $entry->first && $latest
                ? intdiv((int) $entry->first->checked_at->diffInSeconds($latest->checked_at), 120) + 1
                : 0;

            $items[] = array_merge($service, [
                'state' => $state,
                'is_up' => ! $stale && $latest->is_up,
                'status_code' => $latest?->status_code,
                'response_ms' => $latest?->response_ms,
                'error' => $latest?->error,
                'checked_at' => $latest?->checked_at?->toIso8601String(),
                'uptime' => $entry->count ? round($entry->up / $entry->count * 100, 2) : null,
                'has_gaps' => $stale || $entry->total < $expected,
                'maintenance' => $inMaintenance,
                'days' => $days,
            ]);
        }

        $names = array_column($services, 'name', 'key');
        $incidents = [];
        foreach (MonitorIncident::whereNotNull('confirmed_at')
            ->whereIn('service_key', array_column($services, 'key'))
            ->where('confirmed_at', '>=', now()->subDays(30))
            ->latest('confirmed_at')->limit(10)->get() as $incident) {
            $incidents[] = [
                'key' => $incident->incident_key,
                'service' => $names[$incident->service_key] ?? $incident->service_key,
                'confirmed_at' => $incident->confirmed_at?->toIso8601String(),
                'recovered_at' => $incident->recovered_at?->toIso8601String(),
                'closed_reason' => $incident->closed_reason,
            ];
        }

        $lastCheckedAt = null;
        foreach ($items as $item) {
            if ($item['checked_at'] !== null && ($lastCheckedAt === null || $item['checked_at'] > $lastCheckedAt)) {
                $lastCheckedAt = $item['checked_at'];
            }
        }

        return [
            'services' => $items,
            'incidents' => $incidents,
            'maintenance' => $maintenance,
            'lastCheckedAt' => $lastCheckedAt,
            'historyDays' => 30,
        ];
    }
}
