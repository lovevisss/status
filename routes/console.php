<?php

use App\Models\MonitorRecipient;
use App\Models\User;
use App\Services\MonitorAlertDispatcher;
use App\Services\MonitorRunner;
use App\Services\MonitorTestMailQueue;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

Artisan::command('monitor:test-email', function (MonitorTestMailQueue $queue) {
    $recipients = MonitorRecipient::where('is_enabled', true)->get();
    if ($recipients->isEmpty()) {
        $this->error('数据库中没有已启用的邮件收件人。');

        return 1;
    }

    if (! $queue->configured()) {
        $this->error('消息平台 HTTPS 地址或密钥未配置。');

        return 1;
    }

    foreach ($recipients as $recipient) {
        $queue->queue($recipient, respectCooldown: false);
    }

    $this->info('已为 '.$recipients->count().' 位收件人创建测试邮件记录。请运行 monitor:dispatch-alerts 并查询投递状态。');

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
