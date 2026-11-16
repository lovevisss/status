<?php

namespace App\Services;

use App\Models\GatewayMonitor;
use App\Models\MonitorIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewayManager
{
    /** @param array{gateway_number: string, ip: string, location: string, building: int} $data */
    public function update(GatewayMonitor $gateway, array $data): void
    {
        DB::transaction(function () use ($gateway, $data) {
            $current = GatewayMonitor::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
            $targetChanged = $current->ip !== $data['ip'];
            if ($targetChanged) {
                $this->endCycle($current);
                $data['key'] = 'gateway-'.Str::ulid();
            }
            $current->update($data);
        });
    }

    public function setEnabled(GatewayMonitor $gateway, bool $enabled): void
    {
        DB::transaction(function () use ($gateway, $enabled) {
            $current = GatewayMonitor::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
            if ($current->is_enabled === $enabled) {
                return;
            }
            $this->endCycle($current);
            $current->update([
                'is_enabled' => $enabled,
                'key' => $enabled ? 'gateway-'.Str::ulid() : $current->key,
            ]);
        });
    }

    private function endCycle(GatewayMonitor $gateway): void
    {
        foreach (MonitorIncident::where('service_key', $gateway->key)->whereNull('closed_at')->lockForUpdate()->get() as $incident) {
            $incident->update(['closed_at' => now(), 'closed_reason' => 'target_changed']);
            $incident->alerts()->whereNull('platform_task_id')->where('attempts', 0)
                ->where('status', 'pending')->update([
                    'status' => 'cancelled', 'next_attempt_at' => null,
                    'last_error' => '网关监测已停用或目标 IP 已更换',
                ]);
        }
    }
}
