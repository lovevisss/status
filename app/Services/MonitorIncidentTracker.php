<?php

namespace App\Services;

use App\Models\MonitorAlert;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MonitorIncidentTracker
{
    public function __construct(
        private readonly MonitorRecipients $recipients,
        private readonly MonitorMaintenance $maintenance,
    ) {}

    /** @param array{key: string, name: string, url: string} $service */
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

    /** @param array{key: string, name: string, url: string} $service */
    private function enqueue(MonitorIncident $incident, array $service, MonitorCheck $check, string $kind): void
    {
        $outage = $kind === 'outage';
        $eventAt = $check->checked_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $startedAt = $incident->started_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');

        $title = $outage ? "【校园服务故障】{$service['name']}访问异常" : "【校园服务恢复】{$service['name']}已恢复访问";
        $content = implode("\n", [
            $outage ? '连续两次定时检测失败，已确认服务故障。' : '定时检测确认服务已恢复。',
            "服务：{$service['name']}",
            "地址：{$service['url']}",
            "故障开始：{$startedAt}（北京时间）",
            ($outage ? '确认时间：' : '恢复时间：').$eventAt.'（北京时间）',
            '检测结果：'.($check->is_up ? '可访问' : ($check->error ?? '不可访问')),
            '状态页面：'.config('app.url'),
        ]);

        $recipients = $outage ? $this->recipients->all() : $incident->alerts()
            ->where('kind', 'outage')->whereNotNull('recipient_id')->get()
            ->map(fn (MonitorAlert $alert) => ['id' => $alert->recipient_id, 'email' => $alert->recipient_email])
            ->all();

        if ($recipients === []) {
            Log::error('No enabled monitor email recipients are configured in the database.');
            MonitorAlert::create([
                'monitor_incident_id' => $incident->id,
                'kind' => $kind,
                'request_id' => "campus_status:{$kind}:{$incident->incident_key}_config",
                'status' => 'configuration_error',
                'title' => $title,
                'content' => $content,
                'last_error' => '数据库中没有已启用的邮件收件人',
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            MonitorAlert::create([
                'monitor_incident_id' => $incident->id,
                'kind' => $kind,
                'request_id' => MonitorRequestIds::forRecipient($kind, $incident->incident_key, $recipient['id']),
                'status' => 'pending',
                'recipient_id' => $recipient['id'],
                'recipient_email' => $recipient['email'],
                'title' => $title,
                'content' => $content,
                'next_attempt_at' => now(),
            ]);
        }
    }
}
