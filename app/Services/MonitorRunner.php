<?php

namespace App\Services;

use App\Models\MonitorCheck;
use App\Models\MonitorService;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorRunner
{
    public function __construct(
        private readonly MonitorIncidentTracker $incidentTracker,
        private readonly MonitorTargetGuard $targetGuard,
    ) {}

    public function run(bool $evaluateAlerts = true): void
    {
        foreach (MonitorService::enabled()->orderBy('sort_order')->orderBy('id')->get() as $monitor) {
            $service = $monitor->only(['key', 'name', 'url']);
            $start = microtime(true);
            $status = null;
            $error = null;

            try {
                $url = $this->targetGuard->normalize($service['url']);
                for ($redirects = 0; $redirects <= 5; $redirects++) {
                    $remaining = 8 - (microtime(true) - $start);
                    if ($remaining <= 0) {
                        throw new \RuntimeException('连接超时');
                    }

                    $response = Http::connectTimeout(min(4, $remaining))->timeout($remaining)
                        ->withOptions([
                            'allow_redirects' => false,
                            'proxy' => '',
                            'curl' => $this->targetGuard->curlOptions($url),
                        ])
                        ->withUserAgent('CampusStatus/1.0 (+availability monitor)')
                        ->get($url);
                    $status = $response->status();
                    if ($status < 300 || $status >= 400) {
                        break;
                    }

                    $location = $response->header('Location');
                    if ($location === '' || $redirects === 5) {
                        throw new \RuntimeException('跳转次数超限或缺少目标地址');
                    }

                    $nextUrl = (string) UriResolver::resolve(new Uri($url), new Uri($location));
                    $url = $this->targetGuard->normalize($nextUrl, redirect: true);
                }

                $isUp = $status >= 200 && $status < 300;
                if (! $isUp) {
                    $error = 'HTTP '.$status;
                }
            } catch (\Throwable $exception) {
                $isUp = false;
                $message = $exception->getMessage();
                Log::warning('Monitor HTTP probe failed.', ['service_key' => $service['key'], 'error' => $message]);
                $error = match (true) {
                    str_contains($message, 'timed out'), str_contains($message, '连接超时') => '连接超时，目标服务未在 8 秒内响应',
                    str_contains($message, 'TLS connect error'), str_contains($message, 'SSL') => 'TLS 安全连接失败',
                    str_contains($message, 'Could not resolve host'), str_contains($message, '域名解析失败') => '域名解析失败',
                    str_contains($message, '不允许访问'), str_contains($message, '只允许'), str_contains($message, '跳转') => '跳转目标不在允许范围或跳转异常',
                    default => '连接失败，目标服务暂时无法访问',
                };
            }

            DB::transaction(function () use ($monitor, $service, $isUp, $status, $start, $error, $evaluateAlerts) {
                $current = MonitorService::whereKey($monitor->id)->lockForUpdate()->first();
                if (! $current || ! $current->is_enabled || $current->archived_at !== null) {
                    return;
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
            });
        }

        MonitorCheck::where('checked_at', '<', now()->subDays(30))->delete();
    }
}
