<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GatewayRecipient;
use App\Models\MonitorAlert;
use App\Models\MonitorIncident;
use App\Services\MonitorRequestIds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GatewayRecipientController extends Controller
{
    public function index(Request $request): Response
    {
        $recipients = GatewayRecipient::orderBy('id')->get()->map(function (GatewayRecipient $person) {
            $tests = MonitorAlert::where('kind', 'test')->where('recipient_id', $person->person_id)
                ->whereHas('incident', fn ($query) => $query->where('service_key', '__gateway_test__'))
                ->latest('id')->limit(2)->get()->map(fn (MonitorAlert $alert) => $alert->only([
                    'channel', 'status', 'last_error', 'platform_task_id', 'created_at',
                ]));

            return [...$person->only(['id', 'person_id', 'email', 'mobile', 'notify_email', 'notify_sms', 'is_enabled']), 'tests' => $tests];
        });

        return Inertia::render('admin/GatewayRecipients', [
            'recipients' => $recipients,
            'message' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        GatewayRecipient::create($this->validated($request));

        return redirect()->route('admin.gateway-recipients.index')->with('status', '网关处理人员已添加。');
    }

    public function update(Request $request, GatewayRecipient $recipient): RedirectResponse
    {
        $recipient->update($this->validated($request, $recipient));

        return redirect()->route('admin.gateway-recipients.index')->with('status', '网关处理人员已更新；已有通知沿用原快照。');
    }

    public function disable(GatewayRecipient $recipient): RedirectResponse
    {
        $recipient->update(['is_enabled' => false]);

        return redirect()->route('admin.gateway-recipients.index')->with('status', '处理人员已停用。');
    }

    public function enable(GatewayRecipient $recipient): RedirectResponse
    {
        $recipient->update(['is_enabled' => true]);

        return redirect()->route('admin.gateway-recipients.index')->with('status', '处理人员已启用。');
    }

    public function test(GatewayRecipient $recipient): RedirectResponse
    {
        if (! $recipient->is_enabled) {
            throw ValidationException::withMessages(['test' => '请先启用该处理人员。']);
        }
        $recent = MonitorAlert::where('recipient_id', $recipient->person_id)->where('kind', 'test')
            ->where('created_at', '>', now()->subMinutes(5))
            ->whereHas('incident', fn ($query) => $query->where('service_key', '__gateway_test__'))->exists();
        if ($recent) {
            throw ValidationException::withMessages(['test' => '同一处理人员每 5 分钟只能测试一次。']);
        }
        if ($recipient->notify_sms && (! config('monitors.gateway_sms.test_template') || ! config('monitors.gateway_sms.api_token'))) {
            throw ValidationException::withMessages(['test' => '短信 API 密钥或已审批测试模板未配置。']);
        }
        if ($recipient->notify_email && ! config('monitors.alerts.api_token')) {
            throw ValidationException::withMessages(['test' => '邮件 API 密钥未配置。']);
        }
        DB::transaction(function () use ($recipient) {
            $incident = MonitorIncident::create([
                'incident_key' => (string) Str::ulid(), 'service_key' => '__gateway_test__',
                'started_at' => now(), 'confirmed_at' => now(), 'recovered_at' => now(),
                'closed_at' => now(), 'closed_reason' => 'test',
            ]);
            foreach (['email', 'sms'] as $channel) {
                if (! $recipient->{'notify_'.$channel}) {
                    continue;
                }
                $incident->alerts()->create([
                    'kind' => 'test', 'channel' => $channel,
                    'request_id' => MonitorRequestIds::forRecipient('test', $incident->incident_key, $recipient->person_id.':'.$channel),
                    'status' => 'pending', 'recipient_id' => $recipient->person_id,
                    'recipient_email' => $channel === 'email' ? $recipient->email : null,
                    'recipient_mobile' => $channel === 'sms' ? $recipient->mobile : null,
                    'title' => '【测试】宿舍电表网关通知',
                    'content' => $channel === 'sms'
                        ? strtr((string) config('monitors.gateway_sms.test_template'), [
                            '{{gateway}}' => '测试网关', '{{ip}}' => '测试地址',
                            '{{status}}' => '测试', '{{time}}' => now()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                        ])
                        : '这是一封网关测试邮件，不代表真实故障。',
                    'next_attempt_at' => now(),
                ]);
            }
        });

        return redirect()->route('admin.gateway-recipients.index')->with('status', '测试通知已加入队列。');
    }

    /** @return array{person_id: string, email: string|null, mobile: string|null, notify_email: bool, notify_sms: bool} */
    private function validated(Request $request, ?GatewayRecipient $recipient = null): array
    {
        $data = $request->validate([
            'person_id' => ['required', 'string', 'max:120', 'regex:/\A[A-Za-z0-9_-]+\z/', Rule::unique('gateway_recipients')->ignore($recipient?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'regex:/\A1[3-9][0-9]{9}\z/'],
            'notify_email' => ['required', 'boolean'],
            'notify_sms' => ['required', 'boolean'],
        ]);
        if (! $data['notify_email'] && ! $data['notify_sms']) {
            throw ValidationException::withMessages(['notify_email' => '至少选择一种通知方式。']);
        }

        return [
            'person_id' => $data['person_id'], 'email' => $data['email'] ?? null,
            'mobile' => $data['mobile'] ?? null, 'notify_email' => $data['notify_email'],
            'notify_sms' => $data['notify_sms'],
        ];
    }
}
