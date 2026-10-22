<?php

use App\Models\MonitorIncident;
use App\Models\User;
use App\Services\MonitorAlertDispatcher;
use App\Services\MonitorRecipients;
use App\Services\MonitorRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('monitor:check', function (MonitorRunner $runner) {
    $runner->run(evaluateAlerts: true);
    $this->info('Monitor checks completed.');
})->purpose('Check configured campus services');

Schedule::command('monitor:check')->everyTwoMinutes()->withoutOverlapping();

Artisan::command('monitor:dispatch-alerts', function (MonitorAlertDispatcher $dispatcher) {
    $dispatcher->run();
    $this->info('Monitor alert dispatch completed.');
})->purpose('Submit and track monitor email alerts');

Schedule::command('monitor:dispatch-alerts')->everyMinute()->withoutOverlapping();

Artisan::command('monitor:test-email', function (MonitorRecipients $recipients) {
    $list = $recipients->all();
    if ($list === null) {
        $this->error('MONITOR_ALERT_RECIPIENTS 未配置或格式错误。');

        return 1;
    }

    if (! filter_var(config('monitors.alerts.api_url'), FILTER_VALIDATE_URL)
        || ! is_string(config('monitors.alerts.api_token'))
        || config('monitors.alerts.api_token') === '') {
        $this->error('消息平台 API 地址或密钥未配置。');

        return 1;
    }

    $incident = MonitorIncident::create([
        'incident_key' => (string) Str::ulid(),
        'service_key' => '__test__',
        'started_at' => now(),
        'confirmed_at' => now(),
        'recovered_at' => now(),
    ]);
    foreach ($list as $recipient) {
        $incident->alerts()->create([
            'kind' => 'test',
            'request_id' => "campus_status:test:{$incident->incident_key}_{$recipient['id']}",
            'status' => 'pending',
            'recipient_id' => $recipient['id'],
            'recipient_email' => $recipient['email'],
            'title' => '【测试】校园服务状态邮件告警',
            'content' => "这是一封测试邮件，不代表真实服务故障。\n状态页面：".config('app.url'),
            'next_attempt_at' => now(),
        ]);
    }

    $this->info('已为 '.count($list).' 位收件人创建测试邮件记录。请运行 monitor:dispatch-alerts 并查询投递状态。');

    return 0;
})->purpose('Queue clearly labeled test emails for all monitor recipients');

Artisan::command('monitor:admin:activate {email}', function (string $email) {
    $emails = array_filter(array_map(
        fn (string $value) => strtolower(trim($value)),
        explode(',', (string) config('monitors.admin_emails', '')),
    ));
    if (! in_array(strtolower($email), $emails, true)) {
        $this->error('该邮箱未列入 MONITOR_ADMIN_EMAILS。');

        return 1;
    }

    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error('找不到该账号，请先确认生产数据库和邮箱地址。');

        return 1;
    }

    if (! $user->hasVerifiedEmail()) {
        $user->forceFill(['email_verified_at' => now()])->save();
    }
    $this->info('管理员账号邮箱已验证并可通过白名单进入监测管理页。');

    return 0;
})->purpose('Verify an existing allowlisted monitor administrator account');
