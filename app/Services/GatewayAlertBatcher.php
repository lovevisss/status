<?php

namespace App\Services;

use App\Models\GatewayMonitor;
use App\Models\GatewayRecipient;
use App\Models\MonitorAlert;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewayAlertBatcher
{
    public function __construct(private readonly MonitorIncidentTracker $tracker) {}

    public function run(): void
    {
        while ($this->flushOldest()) {
            // Drain all expired windows in one scheduled run.
        }
        $this->queueRecoveries();
    }

    private function flushOldest(): bool
    {
        return DB::transaction(function (): bool {
            $first = MonitorIncident::where('service_key', 'like', 'gateway-%')
                ->whereNotNull('confirmed_at')->whereNull('notification_decided_at')
                ->orderBy('confirmed_at')->orderBy('id')->lockForUpdate()->first();
            if (! $first || $first->confirmed_at->copy()->addMinutes(5)->isFuture()) {
                return false;
            }
            $members = MonitorIncident::where('service_key', 'like', 'gateway-%')
                ->whereNotNull('confirmed_at')->whereNull('notification_decided_at')
                ->where('confirmed_at', '>=', $first->confirmed_at)
                ->where('confirmed_at', '<=', $first->confirmed_at->copy()->addMinutes(5))
                ->orderBy('confirmed_at')->orderBy('id')->lockForUpdate()->get();
            $active = $members->filter(fn (MonitorIncident $incident) => $incident->closed_at === null);
            foreach ($members as $member) {
                $member->update(['notification_decided_at' => now()]);
            }
            if ($active->count() >= 3) {
                $this->createBatch(array_values($active->all()));
            } else {
                foreach ($active as $incident) {
                    $gateway = GatewayMonitor::where('key', $incident->service_key)->where('is_enabled', true)->first();
                    $check = MonitorCheck::where('service_key', $incident->service_key)->latest('id')->first();
                    if ($gateway && $check) {
                        $this->tracker->queueGatewayOutage($incident, [
                            'key' => $gateway->key, 'name' => $gateway->gateway_number.' · '.$gateway->location,
                            'url' => $gateway->ip, 'location' => $gateway->location, 'group' => 'gateway',
                        ], $check);
                    }
                }
            }

            return true;
        });
    }

    /** @param array<int, MonitorIncident> $members */
    private function createBatch(array $members): void
    {
        $notification = MonitorIncident::create([
            'incident_key' => (string) Str::ulid(), 'service_key' => '__gateway_batch__',
            'started_at' => $members[0]->started_at, 'confirmed_at' => now(),
            'closed_at' => now(), 'closed_reason' => 'batch',
        ]);
        $batchId = DB::table('gateway_alert_batches')->insertGetId([
            'notification_incident_id' => $notification->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($members as $member) {
            DB::table('gateway_alert_batch_members')->insert([
                'gateway_alert_batch_id' => $batchId, 'monitor_incident_id' => $member->id,
            ]);
        }
        $count = count($members);
        $details = collect($members)->map(fn (MonitorIncident $incident) => $incident->target_name.'：'.$incident->target_address)->implode("\n");
        $this->queueGroup($notification, 'outage', $count, $details);
    }

    private function queueRecoveries(): void
    {
        foreach (DB::table('gateway_alert_batches')->whereNull('recovery_queued_at')->orderBy('id')->get() as $batch) {
            DB::transaction(function () use ($batch): void {
                $current = DB::table('gateway_alert_batches')->where('id', $batch->id)->whereNull('recovery_queued_at')->lockForUpdate()->first();
                if (! $current) {
                    return;
                }
                $members = MonitorIncident::whereIn('id', DB::table('gateway_alert_batch_members')
                    ->where('gateway_alert_batch_id', $batch->id)->pluck('monitor_incident_id'))->get();
                if ($members->contains(fn (MonitorIncident $incident) => $incident->closed_at === null)) {
                    return;
                }
                if ($members->every(fn (MonitorIncident $incident) => $incident->recovered_at !== null)) {
                    $notification = MonitorIncident::query()->whereKey($current->notification_incident_id)->firstOrFail();
                    $outages = $notification->alerts()->where('kind', 'outage')->get();
                    if ($outages->isNotEmpty() && $outages->every(fn (MonitorAlert $alert) => $alert->status === 'pending' && $alert->attempts === 0)) {
                        $notification->alerts()->where('kind', 'outage')->update([
                            'status' => 'cancelled', 'next_attempt_at' => null,
                            'last_error' => '整批网关已恢复，取消尚未提交的故障通知',
                        ]);
                    } else {
                        $details = $members->map(fn (MonitorIncident $incident) => $incident->target_name.'：'.$incident->target_address)->implode("\n");
                        $this->queueGroup($notification, 'recovery', $members->count(), $details);
                    }
                }
                DB::table('gateway_alert_batches')->where('id', $batch->id)->update(['recovery_queued_at' => now(), 'updated_at' => now()]);
            });
        }
    }

    private function queueGroup(MonitorIncident $notification, string $kind, int $count, string $details): void
    {
        $outage = $kind === 'outage';
        $title = $outage ? "【电表网关故障】{$count}台网关离线" : "【电表网关恢复】{$count}台网关已全部恢复";
        $emailContent = ($outage ? '以下网关已确认故障：' : '以下网关已全部恢复：')."\n".$details."\n状态页面：".config('app.url').'/?tab=gateways';
        $smsTemplate = config('monitors.gateway_sms.batch_'.($outage ? 'outage' : 'recovery').'_template');
        $smsContent = is_string($smsTemplate) ? strtr($smsTemplate, ['{{数量}}' => (string) $count]) : '';
        $smsLength = (int) (strlen(mb_convert_encoding($smsContent, 'UTF-16BE', 'UTF-8')) / 2);
        $recipients = $outage ? GatewayRecipient::where('is_enabled', true)->orderBy('id')->get()
            ->flatMap(function (GatewayRecipient $person) {
                $items = [];
                if ($person->notify_email) {
                    $items[] = ['id' => $person->person_id, 'channel' => 'email', 'email' => $person->email, 'mobile' => null];
                }
                if ($person->notify_sms) {
                    $items[] = ['id' => $person->person_id, 'channel' => 'sms', 'email' => null, 'mobile' => $person->mobile];
                }

                return $items;
            })->all() : $notification->alerts()->where('kind', 'outage')->whereNotNull('recipient_id')->get()
            ->map(fn (MonitorAlert $alert) => [
                'id' => $alert->recipient_id, 'channel' => $alert->channel,
                'email' => $alert->recipient_email, 'mobile' => $alert->recipient_mobile,
            ])->all();
        if ($recipients === []) {
            $notification->alerts()->create([
                'kind' => $kind, 'request_id' => "campus_status:{$kind}:{$notification->incident_key}_config",
                'status' => 'configuration_error', 'title' => $title, 'content' => $emailContent,
                'last_error' => '数据库中没有已启用的网关处理人员',
            ]);
        }
        foreach ($recipients as $recipient) {
            $sms = $recipient['channel'] === 'sms';
            $ready = ! $sms || (config('monitors.gateway_sms.batch_enabled') && $smsContent !== '' && $smsLength <= 70);
            $notification->alerts()->create([
                'kind' => $kind, 'channel' => $recipient['channel'],
                'request_id' => MonitorRequestIds::forRecipient($kind, $notification->incident_key, $recipient['id'].':'.$recipient['channel']),
                'status' => $ready ? 'pending' : 'configuration_error',
                'recipient_id' => $recipient['id'], 'recipient_email' => $recipient['email'],
                'recipient_mobile' => $recipient['mobile'], 'title' => $title,
                'content' => $sms ? $smsContent : $emailContent,
                'next_attempt_at' => $ready ? now() : null,
                'last_error' => $ready ? null : ($smsLength > 70 ? '短信正文超过70个UTF-16码元' : ($smsContent === '' ? '批量短信正文未配置' : '批量短信文案尚未通过MAS审批')),
            ]);
        }
    }
}
