<?php

namespace App\Services;

use App\Models\MonitorAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorAlertDispatcher
{
    public function run(): void
    {
        $due = MonitorAlert::whereIn('status', ['pending', 'queued', 'processing'])
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($due->isEmpty()) {
            return;
        }

        foreach ($due as $alert) {
            if ($alert->created_at->lt(now()->subDay())) {
                $this->fail($alert, '邮件重试超过 24 小时，需人工处理');
            }
        }
        $due = $due->filter(fn (MonitorAlert $alert) => $alert->status !== 'failed');
        if ($due->isEmpty()) {
            return;
        }

        $config = $this->settings();
        if ($config === null) {
            if (Cache::add('monitor-alert-config-warning', true, 3600)) {
                Log::error('Monitor email API is not configured. Set MONITOR_ALERT_API_URL and MONITOR_ALERT_API_TOKEN.');
            }
            foreach ($due as $alert) {
                if ($alert->last_error !== 'MONITOR_ALERT_API_URL 或 MONITOR_ALERT_API_TOKEN 未配置') {
                    $alert->update(['last_error' => 'MONITOR_ALERT_API_URL 或 MONITOR_ALERT_API_TOKEN 未配置']);
                }
            }

            return;
        }

        foreach ($due as $alert) {
            if ($alert->kind === 'recovery' && ! $this->outageWasSubmitted($alert)) {
                continue;
            }

            if ($alert->platform_task_id) {
                $this->query($alert, $config);
            } else {
                $this->submit($alert, $config);
            }
        }
    }

    /** @return array{api_url: string, api_token: string}|null */
    private function settings(): ?array
    {
        $apiUrl = config('monitors.alerts.api_url');
        $apiToken = config('monitors.alerts.api_token');
        if (! is_string($apiUrl) || filter_var($apiUrl, FILTER_VALIDATE_URL) === false
            || ! is_string($apiToken) || $apiToken === '') {
            return null;
        }

        return [
            'api_url' => $apiUrl,
            'api_token' => $apiToken,
        ];
    }

    private function outageWasSubmitted(MonitorAlert $recovery): bool
    {
        $outage = $recovery->incident->alerts()->where('kind', 'outage')
            ->where('recipient_id', $recovery->recipient_id)->first();
        if ($outage?->status === 'accepted') {
            return true;
        }

        if (! $outage || in_array($outage->status, ['failed', 'cancelled', 'configuration_error', 'partial'], true)) {
            $recovery->update([
                'status' => 'cancelled',
                'next_attempt_at' => null,
                'last_error' => '故障通知未送达，取消恢复通知',
            ]);

            return false;
        }

        $recovery->update(['next_attempt_at' => now()->addMinute()]);

        return false;
    }

    /** @param array{api_url: string, api_token: string} $config */
    private function submit(MonitorAlert $alert, array $config): void
    {
        if (! $alert->recipient_id || ! $alert->recipient_email) {
            $this->fail($alert, '收件人快照缺失，需人工处理');

            return;
        }

        $alert->increment('attempts');

        try {
            $response = Http::withToken($config['api_token'])->acceptJson()
                ->connectTimeout(4)->timeout(10)
                ->post($config['api_url'], [
                    'request_id' => $alert->request_id,
                    'channels' => ['email'],
                    'title' => $alert->title,
                    'content' => $alert->content,
                    'recipients' => [[
                        'id' => $alert->recipient_id,
                        'email' => $alert->recipient_email,
                    ]],
                ]);

            if (in_array($response->status(), [200, 202], true) && is_string($response->json('id'))) {
                $status = $response->json('status');
                $status = in_array($status, ['queued', 'processing', 'accepted', 'partial', 'failed'], true) ? $status : 'queued';
                $terminalError = in_array($status, ['partial', 'failed'], true)
                    ? $response->json('deliveries.0.error') ?? '消息平台报告投递失败'
                    : null;
                $alert->update([
                    'platform_task_id' => $response->json('id'),
                    'status' => in_array($status, ['partial', 'failed'], true) ? 'failed' : $status,
                    'next_attempt_at' => in_array($status, ['queued', 'processing'], true) ? now()->addMinute() : null,
                    'last_error' => $terminalError,
                ]);
                $this->logTerminalFailure($alert, $status);

                return;
            }

            if (in_array($response->status(), [401, 422], true) || ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429)) {
                $this->fail($alert, '消息平台拒绝请求：HTTP '.$response->status());

                return;
            }

            $this->retry($alert, '消息平台暂时不可用：HTTP '.$response->status());
        } catch (\Throwable $exception) {
            $this->retry($alert, '消息平台连接失败：'.mb_substr($exception->getMessage(), 0, 300));
        }
    }

    /** @param array{api_url: string, api_token: string} $config */
    private function query(MonitorAlert $alert, array $config): void
    {
        try {
            $response = Http::withToken($config['api_token'])->acceptJson()
                ->connectTimeout(4)->timeout(10)
                ->get(rtrim($config['api_url'], '/').'/'.rawurlencode($alert->platform_task_id));

            if ($response->ok()) {
                $status = $response->json('status');
                if (! in_array($status, ['queued', 'processing', 'accepted', 'partial', 'failed'], true)) {
                    $this->retryPoll($alert, '消息平台返回未知任务状态');

                    return;
                }

                $terminalError = in_array($status, ['partial', 'failed'], true)
                    ? $response->json('deliveries.0.error') ?? '消息平台报告投递失败'
                    : null;
                $alert->update([
                    'status' => in_array($status, ['partial', 'failed'], true) ? 'failed' : $status,
                    'next_attempt_at' => in_array($status, ['queued', 'processing'], true) ? now()->addMinutes(2) : null,
                    'last_error' => $terminalError,
                ]);
                $this->logTerminalFailure($alert, $status);

                return;
            }

            if (in_array($response->status(), [401, 404], true)) {
                $this->fail($alert, '无法查询消息平台任务：HTTP '.$response->status());

                return;
            }

            $this->retryPoll($alert, '查询消息平台任务失败：HTTP '.$response->status());
        } catch (\Throwable $exception) {
            $this->retryPoll($alert, '查询消息平台任务失败：'.mb_substr($exception->getMessage(), 0, 300));
        }
    }

    private function retry(MonitorAlert $alert, string $reason): void
    {
        $minutes = min(15, 2 ** min($alert->attempts - 1, 4));
        $alert->update([
            'next_attempt_at' => now()->addMinutes($minutes),
            'last_error' => $reason,
        ]);
        Log::warning('Monitor email alert will be retried.', ['request_id' => $alert->request_id, 'reason' => $reason]);
    }

    private function retryPoll(MonitorAlert $alert, string $reason): void
    {
        $alert->update(['next_attempt_at' => now()->addMinutes(5), 'last_error' => $reason]);
        Log::warning('Monitor email delivery check will be retried.', ['request_id' => $alert->request_id, 'reason' => $reason]);
    }

    private function fail(MonitorAlert $alert, string $reason): void
    {
        $alert->update(['status' => 'failed', 'next_attempt_at' => null, 'last_error' => $reason]);
        Log::error('Monitor email alert failed.', ['request_id' => $alert->request_id, 'reason' => $reason]);
    }

    private function logTerminalFailure(MonitorAlert $alert, string $status): void
    {
        if (in_array($status, ['partial', 'failed'], true)) {
            Log::error('Monitor email delivery did not complete.', [
                'request_id' => $alert->request_id,
                'platform_task_id' => $alert->platform_task_id,
                'status' => $status,
            ]);
        }
    }
}
