<?php

namespace App\Services;

use App\Models\MonitorAlert;
use App\Models\MonitorIncident;
use App\Models\MonitorRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonitorTestMailQueue
{
    public function configured(): bool
    {
        $url = config('monitors.alerts.api_url');
        $token = config('monitors.alerts.api_token');

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($token) && $token !== '';
    }

    public function queue(MonitorRecipient $recipient, bool $respectCooldown = true): MonitorAlert
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['test' => '消息平台 HTTPS 地址或密钥未配置。']);
        }

        return DB::transaction(function () use ($recipient, $respectCooldown) {
            $current = MonitorRecipient::lockForUpdate()->findOrFail($recipient->id);
            if (! $current->is_enabled) {
                throw ValidationException::withMessages(['test' => '请先启用该收件人。']);
            }
            if ($respectCooldown && $current->last_test_at?->gt(now()->subMinutes(5))) {
                throw ValidationException::withMessages(['test' => '同一收件人每 5 分钟只能发送一次测试邮件。']);
            }

            $incident = MonitorIncident::create([
                'incident_key' => (string) Str::ulid(),
                'service_key' => '__test__',
                'started_at' => now(),
                'confirmed_at' => now(),
                'recovered_at' => now(),
                'closed_at' => now(),
                'closed_reason' => 'test',
            ]);
            $alert = $incident->alerts()->create([
                'kind' => 'test',
                'request_id' => MonitorRequestIds::forRecipient('test', $incident->incident_key, $current->person_id),
                'status' => 'pending',
                'recipient_id' => $current->person_id,
                'recipient_email' => $current->email,
                'title' => '【测试】校园服务状态邮件告警',
                'content' => "这是一封测试邮件，不代表真实服务故障。\n状态页面：".config('app.url'),
                'next_attempt_at' => now(),
            ]);
            $current->update(['last_test_at' => now(), 'last_test_alert_id' => $alert->id]);

            return $alert;
        });
    }
}
