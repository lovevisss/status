<?php

namespace App\Services;

use App\Models\GatewayMonitor;
use App\Models\MonitorCheck;
use Illuminate\Support\Facades\DB;

class GatewayRunner
{
    public function __construct(private readonly GatewayPing $ping, private readonly MonitorIncidentTracker $incidents) {}

    public function run(bool $evaluateAlerts = true): void
    {
        $gateways = GatewayMonitor::where('is_enabled', true)->orderBy('building')->get();
        $ips = [];
        foreach ($gateways as $gateway) {
            $ips[] = $gateway->ip;
        }
        $results = $this->ping->probe($ips);
        foreach ($gateways as $gateway) {
            $result = $results[$gateway->ip] ?? ['up' => false, 'response_ms' => 0, 'error' => '未取得 Ping 结果'];
            if (! ($result['valid'] ?? true)) {
                \Illuminate\Support\Facades\Log::error('Gateway Ping probe unavailable.', ['gateway' => $gateway->gateway_number, 'error' => $result['error']]);
                continue;
            }
            DB::transaction(function () use ($gateway, $result, $evaluateAlerts) {
                $current = GatewayMonitor::whereKey($gateway->id)->lockForUpdate()->first();
                if (! $current?->is_enabled || $current->ip !== $gateway->ip) {
                    return;
                }
                $check = MonitorCheck::create([
                    'service_key' => $gateway->key,
                    'is_up' => $result['up'],
                    'status_code' => null,
                    'response_ms' => $result['response_ms'],
                    'error' => $result['error'],
                    'checked_at' => now(),
                    'is_maintenance' => false,
                ]);
                if ($evaluateAlerts) {
                    $this->incidents->record([
                        'key' => $gateway->key,
                        'name' => $gateway->gateway_number.' · '.$gateway->location,
                        'url' => $gateway->ip,
                        'location' => $gateway->location,
                        'group' => 'gateway',
                    ], $check);
                }
            });
        }
    }
}
