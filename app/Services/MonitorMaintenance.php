<?php

namespace App\Services;

use App\Models\MonitorMaintenanceWindow;
use App\Models\MonitorService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitorMaintenance
{
    public function activeFor(MonitorService $service, CarbonInterface $at): bool
    {
        return MonitorMaintenanceWindow::whereHas('services', fn ($query) => $query->whereKey($service->id))
            ->whereNull('cancelled_at')->whereNull('ended_at')
            ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)->exists();
    }

    public function endedSince(string $serviceKey, CarbonInterface $since, CarbonInterface $at): bool
    {
        return MonitorMaintenanceWindow::whereHas('services', fn ($query) => $query->where('monitor_services.key', $serviceKey))
            ->whereNull('cancelled_at')->where('starts_at', '>', $since)->where('starts_at', '<=', $at)
            ->where(fn ($query) => $query->where('ends_at', '<=', $at)->orWhere('ended_at', '<=', $at))
            ->exists();
    }

    /** @param list<int> $serviceIds */
    public function save(?MonitorMaintenanceWindow $window, array $serviceIds, string $title, string $description, Carbon $start, Carbon $end): MonitorMaintenanceWindow
    {
        return DB::transaction(function () use ($window, $serviceIds, $title, $description, $start, $end) {
            $ids = array_values(array_unique($serviceIds));
            $services = MonitorService::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if (count($ids) === 0 || $services->count() !== count($ids)
                || $services->contains(fn (MonitorService $service) => ! $service->is_enabled || $service->archived_at !== null)) {
                throw ValidationException::withMessages(['services' => '请选择至少一项当前启用的监测服务。']);
            }

            $overlap = MonitorMaintenanceWindow::whereHas('services', fn ($query) => $query->whereIn('monitor_services.id', $ids))
                ->whereNull('cancelled_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $start));
            if ($window) {
                $overlap->where('monitor_maintenance_windows.id', '!=', $window->id);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['services' => '所选服务在该时段已有计划维护。']);
            }

            if ($window) {
                $window->update(compact('title', 'description') + ['starts_at' => $start, 'ends_at' => $end]);
            } else {
                $window = MonitorMaintenanceWindow::create(compact('title', 'description') + ['starts_at' => $start, 'ends_at' => $end]);
            }
            $window->services()->sync($ids);

            return $window;
        });
    }
}
