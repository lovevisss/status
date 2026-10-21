# 校园服务状态

Laravel + Vue 状态页。从校内服务器每 2 分钟探测学校官网、党建平台和 WebVPN，公开显示最近 30 天的可访问性。连续两次定时探测失败确认故障，一次成功即恢复；故障和恢复邮件通过[消息平台邮件 API](http://messager.test/docs/email)异步发送。

## 本地开发

需要 PHP 8.3+、Composer、Node.js，以及 SQLite 或 MySQL。安装依赖、复制 `.env.example` 为 `.env`，然后运行：

```bash
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

另开终端执行 `php artisan schedule:work`，否则不会持续探测。也可运行 `php artisan monitor:check` 手动触发一次定时探测；该命令参与故障判定，运行前请确认告警配置。

## Linux 部署（通过 git pull 更新）

1. 在服务器上准备 PHP 8.3+、所需 PHP 扩展、Composer、Node.js、MySQL／MariaDB，以及可访问三项监测服务和消息平台的网络。Web 服务器站点根目录指向项目的 `public/`，域名使用 `https://status.zufedfc.edu.cn`。只有 `storage/` 和 `bootstrap/cache/` 需要 Web／CLI 用户的写权限。
2. 从 Git 仓库拉取项目。首次部署时复制 `.env.example` 为 `.env` 并生成 `APP_KEY`；以后更新必须保留现有 `.env`、数据库和 `APP_KEY`。
3. 在服务器 `.env` 设置 `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL=https://status.zufedfc.edu.cn`、MySQL 连接信息及下面的邮件配置。密钥和真实收件人信息只存服务器环境，不提交 Git。
4. 在项目目录运行：

```bash
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan schedule:list
```

5. 给运行 PHP 的用户配置一条 cron，每分钟执行 Laravel 调度器；请将示例目录和 PHP 路径改为服务器实际路径：

```cron
* * * * * cd /srv/campus-status && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

cron 负责每 2 分钟探测一次、每分钟派发并查询邮件状态。不要在多台服务器上重复配置同一个调度任务。重启服务器后确认 cron 已启动，并观察 24 小时探测记录。探测停止超过 5 分钟时，仍可访问的状态页会显示“监测中断／状态未知”；整台服务器宕机时本站无法自行发信。服务器日志、cron 日志和 `monitor_checks` 表可用于排查。

## 邮件配置

```dotenv
MONITOR_ALERT_API_URL=http://messager.test/api/v1/notifications
MONITOR_ALERT_API_TOKEN=消息平台签发的Bearer密钥
MONITOR_ALERT_RECIPIENTS='[{"id":"20230001","email":"admin1@example.edu.cn"},{"id":"20230002","email":"admin2@example.edu.cn"}]'
```

`MONITOR_ALERT_RECIPIENTS` 是 JSON 数组，学工号不可重复。所有人接收全部服务的通知；事件发生时固定收件人名单，每人独立保存提交编号、平台任务 ID、状态和错误。更改 `.env` 后运行 `php artisan config:cache`。消息平台自己的邮件队列 worker 必须运行；平台返回 `accepted` 表示 SMTP 已接受，不保证进入收件箱。

网络超时和限流会用原 `request_id` 延迟重试，最多 24 小时。鉴权、参数和平台最终投递失败保留在 `monitor_alerts` 表及 Laravel 日志中，需人工处理。服务恢复前尚未提交的故障邮件会被取消，其对应恢复邮件也取消。收件人配置缺失或无效时，事件保留配置错误记录，不发邮件。

上线前可运行 `php artisan monitor:test-email` 创建明确标注“测试”的邮件，再运行 `php artisan monitor:dispatch-alerts`；测试事件不出现在公开异常记录中。等待消息平台异步处理后，再运行派发命令查询最终状态。测试实际服务故障请使用模拟目标，不要中断生产服务。

## 判定与记录

- 探测跟随最多 5 次跳转；最终 HTTP 2xx 为正常，HTTPS 保留证书校验，每项请求最多等待 8 秒。
- 首次失败显示“可能异常／待复核”；连续两次定时失败确认故障。一次成功恢复。探测中断超过 5 分钟时，未确认的失败次数从下次探测重新计算。
- 页面每 2 分钟读取一次已有结果，不允许访客触发探测。可用率仅按实际采样结果计算，页面标明采样缺口；公开页面不展示底层连接异常原文。
- 探测记录保留 30 天；MySQL 中的故障与邮件记录应纳入现有每日备份。

## 验证

```bash
php artisan test
npm run check
npm run types:check
npm run build
```

生产验收检查三项服务和消息平台的网络连通性、cron 重启后恢复、24 小时采样连续性，以及带“测试”标记邮件的最终投递状态。
