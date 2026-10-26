<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitorMaintenanceWindow;
use App\Models\MonitorService;
use App\Services\MonitorMaintenance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MonitorMaintenanceController extends Controller
{
    public function index(Request $request): Response
    {
        $currentServices = MonitorService::enabled()->orderBy('sort_order')->get(['id', 'name', 'url']);
        $windows = MonitorMaintenanceWindow::with('services:id,key,name,url,is_enabled,archived_at')
            ->where('ends_at', '>=', now()->subDays(30))->orderBy('starts_at')->get()
            ->map(function (MonitorMaintenanceWindow $window) {
                $validServices = $window->services->filter(fn (MonitorService $service) => $service->is_enabled && $service->archived_at === null);

                return [
                    'id' => $window->id,
                    'title' => $window->title,
                    'description' => $window->description,
                    'starts_at' => $window->starts_at->toIso8601String(),
                    'ends_at' => $window->ends_at->toIso8601String(),
                    'effective_end_at' => $window->effectiveEnd()->toIso8601String(),
                    'cancelled_at' => $window->cancelled_at?->toIso8601String(),
                    'ended_at' => $window->ended_at?->toIso8601String(),
                    'service_ids' => $validServices->pluck('id')->all(),
                    'service_names' => $window->services->pluck('name')->all(),
                    'needs_attention' => $validServices->count() !== $window->services->count(),
                ];
            });

        return Inertia::render('admin/Maintenance', [
            'services' => $currentServices,
            'windows' => $windows,
            'message' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, MonitorMaintenance $maintenance): RedirectResponse
    {
        [$data, $start, $end] = $this->validated($request);
        $maintenance->save(null, $data['services'], $data['title'], $data['description'], $start, $end);

        return redirect()->route('admin.maintenance.index')->with('status', '维护计划已保存。开始前 7 天会在公开页面预告。');
    }

    public function update(Request $request, MonitorMaintenanceWindow $maintenance, MonitorMaintenance $manager): RedirectResponse
    {
        $this->ensureOpen($maintenance);
        if ($maintenance->starts_at->lte(now())) {
            $end = $this->localTime($request->validate(['ends_at' => ['required', 'string']])['ends_at'], 'ends_at');
            if ($end->lte(now())) {
                throw ValidationException::withMessages(['ends_at' => '结束时间必须晚于当前时间。']);
            }
            DB::transaction(function () use ($maintenance, $end) {
                $window = MonitorMaintenanceWindow::lockForUpdate()->findOrFail($maintenance->id);
                $serviceIds = $window->services()->pluck('monitor_services.id')->all();
                $overlap = MonitorMaintenanceWindow::whereHas('services', fn ($query) => $query->whereIn('monitor_services.id', $serviceIds))
                    ->where('monitor_maintenance_windows.id', '!=', $window->id)
                    ->whereNull('cancelled_at')->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $window->starts_at)
                    ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $window->starts_at));
                if ($overlap->exists()) {
                    throw ValidationException::withMessages(['ends_at' => '延长后的时段与同一服务的其他维护重叠。']);
                }
                $window->update(['ends_at' => $end]);
            });
        } else {
            [$data, $start, $end] = $this->validated($request);
            $manager->save($maintenance, $data['services'], $data['title'], $data['description'], $start, $end);
        }

        return redirect()->route('admin.maintenance.index')->with('status', '维护计划已更新。');
    }

    public function cancel(MonitorMaintenanceWindow $maintenance): RedirectResponse
    {
        $this->ensureOpen($maintenance);
        if ($maintenance->starts_at->lte(now())) {
            throw ValidationException::withMessages(['maintenance' => '已开始的维护请使用提前结束。']);
        }
        $maintenance->update(['cancelled_at' => now()]);

        return redirect()->route('admin.maintenance.index')->with('status', '维护计划已取消。');
    }

    public function finish(MonitorMaintenanceWindow $maintenance): RedirectResponse
    {
        $this->ensureOpen($maintenance);
        if ($maintenance->starts_at->gt(now())) {
            throw ValidationException::withMessages(['maintenance' => '尚未开始的维护请使用取消。']);
        }
        $maintenance->update(['ended_at' => now()]);

        return redirect()->route('admin.maintenance.index')->with('status', '维护已提前结束。');
    }

    private function ensureOpen(MonitorMaintenanceWindow $maintenance): void
    {
        if ($maintenance->cancelled_at || $maintenance->ended_at || $maintenance->ends_at->lte(now())) {
            throw ValidationException::withMessages(['maintenance' => '该维护计划已结束或取消。']);
        }
    }

    /** @return array{0: array{title: string, description: string, services: list<int>}, 1: Carbon, 2: Carbon} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:2000'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['required', 'integer', 'distinct'],
            'starts_at' => ['required', 'string'],
            'ends_at' => ['required', 'string'],
        ]);
        $start = $this->localTime($data['starts_at']);
        $end = $this->localTime($data['ends_at'], 'ends_at');
        if ($start->lte(now())) {
            throw ValidationException::withMessages(['starts_at' => '开始时间必须晚于当前时间。']);
        }
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => '结束时间必须晚于开始时间。']);
        }

        return [[
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'services' => array_values(array_map(intval(...), $data['services'])),
        ], $start, $end];
    }

    private function localTime(string $value, string $field = 'starts_at'): Carbon
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value)) {
            throw ValidationException::withMessages([$field => '请使用北京时间选择完整日期和时间。']);
        }
        try {
            $time = Carbon::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Shanghai');
        } catch (\Throwable) {
            $time = false;
        }
        if ($time === false || $time->format('Y-m-d\TH:i') !== $value) {
            throw ValidationException::withMessages([$field => '日期或时间无效。']);
        }

        return $time->utc();
    }
}
