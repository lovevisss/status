<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitorService;
use App\Services\MonitorServiceManager;
use App\Services\MonitorTargetGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MonitorServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $current = MonitorService::current()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MonitorService $service) => $service->only([
                'id', 'name', 'description', 'url', 'is_enabled',
            ]));
        $archived = MonitorService::whereNotNull('archived_at')
            ->where('archived_at', '>=', now()->subDays(30))
            ->latest('archived_at')->limit(20)->get()
            ->map(fn (MonitorService $service) => $service->only([
                'id', 'name', 'url', 'archived_at',
            ]));

        return Inertia::render('admin/Monitors', [
            'services' => $current,
            'archived' => $archived,
            'message' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, MonitorTargetGuard $guard, MonitorServiceManager $manager): RedirectResponse
    {
        $data = $this->validated($request, $guard);
        $manager->create($data['name'], $data['description'], $data['url']);

        return redirect()->route('admin.monitors.index')->with('status', '监测网址已添加，将在下一次定时任务中检测。');
    }

    public function update(Request $request, MonitorService $monitor, MonitorTargetGuard $guard, MonitorServiceManager $manager): RedirectResponse
    {
        $data = $this->validated($request, $guard);
        $manager->update($monitor, $data['name'], $data['description'], $data['url']);

        return redirect()->route('admin.monitors.index')->with('status', '监测项已更新。');
    }

    public function disable(MonitorService $monitor, MonitorServiceManager $manager): RedirectResponse
    {
        $manager->disable($monitor);

        return redirect()->route('admin.monitors.index')->with('status', '监测已停用，公开页面不再显示该服务。');
    }

    public function enable(MonitorService $monitor, MonitorServiceManager $manager): RedirectResponse
    {
        $manager->enable($monitor);

        return redirect()->route('admin.monitors.index')->with('status', '监测已启用，将开始新的监测周期。');
    }

    /** @return array{name: string, description: string, url: string} */
    private function validated(Request $request, MonitorTargetGuard $guard): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $url = $guard->normalize($data['url']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }

        return [
            'name' => trim($data['name']),
            'description' => trim($data['description'] ?? ''),
            'url' => $url,
        ];
    }
}
