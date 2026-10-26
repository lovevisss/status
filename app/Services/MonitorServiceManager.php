<?php

namespace App\Services;

use App\Models\MonitorIncident;
use App\Models\MonitorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonitorServiceManager
{
    public function create(string $name, string $description, string $url): MonitorService
    {
        return DB::transaction(function () use ($name, $description, $url) {
            $this->ensureUrlAvailable($url);

            return MonitorService::create([
                'key' => (string) Str::ulid(),
                'name' => $name,
                'description' => $description,
                'url' => $url,
                'active_url_hash' => hash('sha256', $url),
                'is_enabled' => true,
                'sort_order' => (int) MonitorService::max('sort_order') + 1,
            ]);
        });
    }

    public function update(MonitorService $service, string $name, string $description, string $url): void
    {
        DB::transaction(function () use ($service, $name, $description, $url) {
            $current = MonitorService::current()->lockForUpdate()->findOrFail($service->id);
            if ($current->url === $url) {
                $current->update(['name' => $name, 'description' => $description]);

                return;
            }

            if ($current->is_enabled) {
                $this->ensureUrlAvailable($url, $current->id);
            }
            $wasEnabled = $current->is_enabled;
            $this->archive($current, 'url_changed');
            $this->newVersion($current, $name, $description, $url, $wasEnabled);
        });
    }

    public function disable(MonitorService $service): void
    {
        DB::transaction(function () use ($service) {
            $current = MonitorService::current()->lockForUpdate()->findOrFail($service->id);
            if (! $current->is_enabled) {
                return;
            }

            $current->update(['is_enabled' => false, 'active_url_hash' => null]);
            $this->closeIncidents($current, 'disabled');
        });
    }

    public function enable(MonitorService $service): void
    {
        DB::transaction(function () use ($service) {
            $current = MonitorService::current()->lockForUpdate()->findOrFail($service->id);
            if ($current->is_enabled) {
                return;
            }

            $this->ensureUrlAvailable($current->url, $current->id);
            $this->archive($current, 'reenabled');
            $this->newVersion($current, $current->name, $current->description, $current->url, true);
        });
    }

    private function archive(MonitorService $service, string $reason): void
    {
        $service->update(['archived_at' => now(), 'is_enabled' => false, 'active_url_hash' => null]);
        $this->closeIncidents($service, $reason);
    }

    private function closeIncidents(MonitorService $service, string $reason): void
    {
        $incidents = MonitorIncident::where('service_key', $service->key)
            ->whereNull('closed_at')->lockForUpdate()->get();
        foreach ($incidents as $incident) {
            $incident->update(['closed_at' => now(), 'closed_reason' => $reason]);
            $incident->alerts()->whereNull('platform_task_id')->where('attempts', 0)
                ->where('status', 'pending')->update([
                    'status' => 'cancelled',
                    'next_attempt_at' => null,
                    'last_error' => '监测项已停用或地址已更换',
                ]);
        }
    }

    private function newVersion(MonitorService $old, string $name, string $description, string $url, bool $enabled): void
    {
        MonitorService::create([
            'key' => (string) Str::ulid(),
            'name' => $name,
            'description' => $description,
            'url' => $url,
            'active_url_hash' => $enabled ? hash('sha256', $url) : null,
            'is_enabled' => $enabled,
            'sort_order' => $old->sort_order,
        ]);
    }

    private function ensureUrlAvailable(string $url, ?int $exceptId = null): void
    {
        $query = MonitorService::enabled()->where('url', $url);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'url' => '该地址已经在监测中。',
            ]);
        }
    }
}
