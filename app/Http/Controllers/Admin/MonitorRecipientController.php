<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitorRecipient;
use App\Services\MonitorTestMailQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MonitorRecipientController extends Controller
{
    public function index(Request $request): Response
    {
        $recipients = MonitorRecipient::with('lastTestAlert')->orderBy('id')->get()
            ->map(fn (MonitorRecipient $recipient) => [
                ...$recipient->only(['id', 'person_id', 'email', 'is_enabled']),
                'last_test' => $recipient->lastTestAlert ? $recipient->lastTestAlert->only([
                    'status', 'last_error', 'platform_task_id', 'created_at',
                ]) : null,
            ]);

        return Inertia::render('admin/Recipients', [
            'recipients' => $recipients,
            'message' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MonitorRecipient::create($this->validated($request));

        return redirect()->route('admin.recipients.index')->with('status', '收件人已添加，后续故障将通知此人。');
    }

    public function update(Request $request, MonitorRecipient $recipient): RedirectResponse
    {
        $data = $this->validated($request, $recipient);
        if ($recipient->person_id !== $data['person_id'] || $recipient->email !== $data['email']) {
            $data['last_test_alert_id'] = null;
        }
        $recipient->update($data);

        return redirect()->route('admin.recipients.index')->with('status', '收件人已更新；已有邮件沿用原快照。');
    }

    public function disable(MonitorRecipient $recipient): RedirectResponse
    {
        $recipient->update(['is_enabled' => false]);

        return redirect()->route('admin.recipients.index')->with('status', '收件人已停用，后续故障不再通知此人。');
    }

    public function enable(MonitorRecipient $recipient): RedirectResponse
    {
        $recipient->update(['is_enabled' => true]);

        return redirect()->route('admin.recipients.index')->with('status', '收件人已启用。');
    }

    public function test(MonitorRecipient $recipient, MonitorTestMailQueue $queue): RedirectResponse
    {
        $queue->queue($recipient);

        return redirect()->route('admin.recipients.index')->with('status', '测试邮件已加入队列，通常在一分钟内提交。');
    }

    /** @return array{person_id: string, email: string|null} */
    private function validated(Request $request, ?MonitorRecipient $recipient = null): array
    {
        $data = $request->validate([
            'person_id' => ['required', 'string', 'max:120', 'regex:/\A[A-Za-z0-9_-]+\z/',
                Rule::unique('monitor_recipients', 'person_id')->ignore($recipient?->id)],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        return [
            'person_id' => $data['person_id'],
            'email' => $data['email'] ?? null,
        ];
    }
}
