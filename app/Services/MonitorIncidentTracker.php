<?php

namespace App\Services;

use App\Models\MonitorAlert;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\GatewayRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MonitorIncidentTracker
{
    public function __construct(
        private readonly MonitorRecipients $recipients,
        private readonly MonitorMaintenance $maintenance,
    ) {}

    /** @param array{key: string, name: string, url: string, group?: string, location?: string} $service */
    public function record(array $service, MonitorCheck $check): void
    {
        DB::transaction(function () use ($service, $check) {
            $incident = MonitorIncident::where('service_key', $service['key'])
                ->whereNull('closed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($check->is_maintenance) {
                if (! $incident) {
                    return;
                }
                if (! $incident->confirmed_at) {
                    $incident->delete();

                    return;
                }
                if (! $check->is_up) {
                    return;
                }
            }

            if (! $check->is_up) {
                if ($incident && ! $incident->confirmed_at
                    && $this->maintenance->endedSince($service['key'], $incident->started_at, $check->checked_at)) {
                    $incident->delete();
                    $incident = null;
                }
                if ($incident && ! $incident->confirmed_at
                    && $incident->started_at->diffInSeconds($check->checked_at) > 300) {
                    $incident->delete();
                    $incident = null;
                }

                if (! $incident) {
                    MonitorIncident::create([
                        'incident_key' => (string) Str::ulid(),
                        'service_key' => $service['key'],
                        'target_name' => $service['name'],
                        'target_address' => $service['url'],
                        'started_at' => $check->checked_at,
                    ]);

                    return;
                }

                if ($incident->confirmed_at) {
                    return;
                }

                $incident->consecutive_failures++;
                $incident->save();
                if ($incident->consecutive_failures === 2) {
                    $incident->update(['confirmed_at' => $check->checked_at]);
                    $this->enqueue($incident, $service, $check, 'outage');
                }

                return;
            }

            if (! $incident) {
                return;
            }

            $incident->update([
                'recovered_at' => $check->checked_at,
                'closed_at' => $check->checked_at,
                'closed_reason' => 'recovered',
            ]);
            if ($incident->confirmed_at) {
                $this->enqueue($incident, $service, $check, 'recovery');
                foreach ($incident->alerts()->where('kind', 'outage')->get() as $outage) {
                    if ($outage->status === 'pending' && ! $outage->platform_task_id && $outage->attempts === 0) {
                        $outage->update([
                            'status' => 'cancelled',
                            'next_attempt_at' => null,
                            'last_error' => '服务已恢复，取消尚未提交的故障通知',
                        ]);
                        $incident->alerts()->where('kind', 'recovery')
                            ->where('recipient_id', $outage->recipient_id)
                            ->where('channel', $outage->channel)
                            ->update([
                                'status' => 'cancelled',
                                'next_attempt_at' => null,
                                'last_error' => '故障通知未送达，取消恢复通知',
                            ]);
                    }
                }
            }
        });
    }

    /** @param array{key: string, name: string, url: string, group?: string, location?: string} $service */
    private function enqueue(MonitorIncident $incident, array $service, MonitorCheck $check, string $kind): void
    {
        $outage = $kind === 'outage';
        $eventAt = $check->checked_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $startedAt = $incident->started_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');

        $gateway = ($service['group'] ?? 'service') === 'gateway';
        $title = $gateway
            ? ($outage ? "【电表网关故障】{$service['name']}离线" : "【电表网关恢复】{$service['name']}已在线")
            : ($outage ? "【校园服务故障】{$service['name']}访问异常" : "【校园服务恢复】{$service['name']}已恢复访问");
        $content = implode("\n", [
            $outage ? '连续两次定时检测失败，已确认服务故障。' : '定时检测确认服务已恢复。',
            "服务：{$service['name']}",
            "地址：{$service['url']}",
            "故障开始：{$startedAt}（北京时间）",
            ($outage ? '确认时间：' : '恢复时间：').$eventAt.'（北京时间）',
            '检测结果：'.($check->is_up ? '可访问' : ($check->error ?? '不可访问')),
            '状态页面：'.config('app.url'),
        ]);

        $recipients = $outage ? ($gateway ? GatewayRecipient::where('is_enabled', true)->orderBy('id')->get()
            ->flatMap(function (GatewayRecipient $person) {
                $items = [];
                if ($person->notify_email) {
                    $items[] = ['id' => $person->person_id, 'email' => $person->email, 'mobile' => null, 'channel' => 'email'];
                }
                if ($person->notify_sms) {
                    $items[] = ['id' => $person->person_id, 'email' => null, 'mobile' => $person->mobile, 'channel' => 'sms'];
                }

                return $items;
            })->all() : $this->recipients->all()) : $incident->alerts()
            ->where('kind', 'outage')->whereNotNull('recipient_id')->get()
            ->map(fn (MonitorAlert $alert) => [
                'id' => $alert->recipient_id, 'email' => $alert->recipient_email,
                'mobile' => $alert->recipient_mobile, 'channel' => $alert->channel,
            ])
            ->all();

        if ($recipients === []) {
            Log::error('No enabled monitor recipients are configured in the database.', ['group' => $gateway ? 'gateway' : 'service']);
            MonitorAlert::create([
                'monitor_incident_id' => $incident->id,
                'kind' => $kind,
                'request_id' => "campus_status:{$kind}:{$incident->incident_key}_config",
                'status' => 'configuration_error',
                'title' => $title,
                'content' => $content,
                'last_error' => $gateway ? '数据库中没有已启用的网关处理人员' : '数据库中没有已启用的邮件收件人',
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            $channel = $recipient['channel'] ?? 'email';
            $smsTemplate = config('monitors.gateway_sms.'.($outage ? 'outage_template' : 'recovery_template'));
            $smsReady = ! $gateway || $channel !== 'sms' || (is_string($smsTemplate) && trim($smsTemplate) !== ''
                && is_string(config('monitors.alerts.api_token')) && config('monitors.alerts.api_token') !== '');
            $smsContent = $gateway && $channel === 'sms' && $smsReady
                ? strtr($smsTemplate, [
                    '{{ip}}' => $service['url'], '{{安装位置}}' => $service['location'] ?? '',
                ]) : $content;
            MonitorAlert::create([
                'monitor_incident_id' => $incident->id,
                'kind' => $kind,
                'request_id' => MonitorRequestIds::forRecipient($kind, $incident->incident_key, $gateway ? $recipient['id'].':'.$channel : $recipient['id']),
                'status' => $smsReady ? 'pending' : 'configuration_error',
                'recipient_id' => $recipient['id'],
                'recipient_email' => $recipient['email'],
                'recipient_mobile' => $recipient['mobile'] ?? null,
                'channel' => $channel,
                'title' => $title,
                'content' => $smsContent,
                'next_attempt_at' => $smsReady ? now() : null,
                'last_error' => $smsReady ? null : 'MONITOR_ALERT_API_TOKEN 或短信模板未配置',
            ]);
        }
    }
}
