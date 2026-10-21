<?php

namespace App\Services;

use App\Models\MonitorCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorRunner
{
    public function __construct(private readonly MonitorIncidentTracker $incidentTracker) {}

    public function run(bool $evaluateAlerts = true): void
    {
        foreach (config('monitors.services', []) as $service) {
            $start = microtime(true);
            $status = null;
            $error = null;

            try {
                $response = Http::connectTimeout(4)->timeout(8)
                    ->withOptions(['allow_redirects' => ['max' => 5]])
                    ->withUserAgent('CampusStatus/1.0 (+availability monitor)')
                    ->get($service['url']);
                $status = $response->status();
                $isUp = $status >= 200 && $status < 300;
                if (! $isUp) {
                    $error = 'HTTP '.$status;
                }
            } catch (\Throwable $exception) {
                $isUp = false;
                $message = $exception->getMessage();
                Log::warning('Monitor HTTP probe failed.', ['service_key' => $service['key'], 'error' => $message]);
                $error = match (true) {
                    str_contains($message, 'timed out') => '连接超时，目标服务未在 8 秒内响应',
                    str_contains($message, 'TLS connect error'), str_contains($message, 'SSL') => 'TLS 安全连接失败',
                    str_contains($message, 'Could not resolve host') => '域名解析失败',
                    default => '连接失败，目标服务暂时无法访问',
                };
            }

            $check = MonitorCheck::create([
                'service_key' => $service['key'],
                'is_up' => $isUp,
                'status_code' => $status,
                'response_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => $error,
                'checked_at' => now(),
            ]);

            if ($evaluateAlerts) {
                $this->incidentTracker->record($service, $check);
            }
        }

        MonitorCheck::where('checked_at', '<', now()->subDays(30))->delete();
    }
}
