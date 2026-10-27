# 校园服务状态

Laravel + Vue 状态页。从校内服务器每 2 分钟探测已启用的服务，初始清单包括学校官网、党建平台和 WebVPN；公开显示最近 30 天的可访问性。连续两次定时探测失败确认故障，一次成功即恢复；故障和恢复邮件通过[消息平台邮件 API](https://messenger.zufedfc.edu.cn/docs/email)异步发送。

公开页面的异常记录可点击查看详情，显示首次失败、确认故障、恢复或监测终止时间及持续时长。已确认的历史故障可通过 `/incidents/{故障编号}` 直接访问；短暂失败和测试事件不会公开。

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
3. 在服务器 `.env` 设置 `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL=https://status.zufedfc.edu.cn`、MySQL 连接信息、`MONITOR_ADMIN_EMAILS` 及下面的邮件配置。密钥只存服务器环境，不提交 Git。本次已在聊天中共享的旧密钥须在消息平台轮换；服务器使用轮换后的新密钥。

   登录依赖同一浏览器会话中的 CSRF 令牌。生产环境还应设置 `SESSION_SECURE_COOKIE=true`、`SESSION_DOMAIN=null`（除非确有跨子域共享需求），并在 Web 服务器将 HTTP 永久跳转到 HTTPS。`APP_URL` 只用于生成地址，不会替代 Web 服务器的跳转。如果 HTTPS 由反向代理终止，设置 `TRUSTED_PROXIES` 为该代理的实际 IP 或 CIDR（多个值用英文逗号分隔），并确认代理传递 `X-Forwarded-Proto: https`；不要信任任意来源的转发头。修改后执行 `php artisan optimize:clear && php artisan config:cache`。
4. 在项目目录运行：

```bash
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan schedule:list
```

首次迁移会把原来的三项服务导入 `monitor_services` 表。本次收件人迁移会一次性读取当时的 `MONITOR_ALERT_RECIPIENTS`，导入 `monitor_recipients` 表；迁移完成后，数据库成为唯一的通知名单来源。请在运行迁移前保留服务器原有名单，迁移后到 `/admin/recipients` 核对人数和学工号，确认无误后可从 `.env` 删除旧名单变量。已有管理员账号的邮箱加入 `MONITOR_ADMIN_EMAILS` 后，执行 `php artisan monitor:admin:activate 管理员邮箱` 验证该账号，再用原密码登录 `/admin/monitors`。该命令只激活数据库中已经存在且在白名单内的账号；公开注册现已关闭。部署时请确认生产数据库中确有该账号。白名单多个邮箱用英文逗号分隔，例如 `MONITOR_ADMIN_EMAILS=one@example.edu.cn,two@example.edu.cn`。

管理页可新增、编辑、停用及重新启用监测项。新项目等待下一次两分钟任务首次检测。更换网址或重新启用时建立新监测周期，旧探测记录保留至 30 天清理期满；停用或更换网址会结束旧故障周期，并取消尚未提交的告警。已提交的消息平台任务继续追踪投递状态。

管理员可在 `/admin/maintenance` 为一个或多个当前启用的服务安排计划维护，填写标题、说明和北京时间起止时间。开始前 7 天，公开页面会展示预告；维护期间仍持续探测，但该时段采样不计入 30 天可用率，新故障不发送邮件。维护结束后重新累计两次失败。维护前已确认的故障及对应故障、恢复邮件继续处理。计划可在开始前编辑或取消，开始后可调整结束时间或提前结束；同一服务的维护时段不能重叠。服务更换 URL 或重新启用会建立新监测周期，旧维护计划不会自动套用，后台会提示重新选择。已结束的维护在公开页面保留 30 天。

初始目标仅允许 `zufedfc.edu.cn` 及其子域名，或 `10.0.0.0/8` 地址；端口默认限制为 80、443、8080、8443。需要额外认证跳转域名时，在服务器配置 `MONITOR_ALLOWED_REDIRECT_HOSTS=login.example.edu.cn`，多个域名用英文逗号分隔。可通过 `MONITOR_ALLOWED_PORTS` 调整端口列表。每次连接和跳转都会检查解析地址，拒绝本机、链路本地和云元数据地址。改动环境变量后运行 `php artisan config:cache`。

5. 给运行 PHP 的用户配置一条 cron，每分钟执行 Laravel 调度器；请将示例目录和 PHP 路径改为服务器实际路径：

```cron
* * * * * cd /srv/campus-status && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

cron 负责每 2 分钟探测一次、每分钟派发并查询邮件状态。不要在多台服务器上重复配置同一个调度任务。重启服务器后确认 cron 已启动，并观察 24 小时探测记录。探测停止超过 5 分钟时，仍可访问的状态页会显示“监测中断／状态未知”；整台服务器宕机时本站无法自行发信。服务器日志、cron 日志和 `monitor_checks` 表可用于排查。

## 邮件配置

```dotenv
MONITOR_ALERT_API_URL=https://messenger.zufedfc.edu.cn/api/v1/notifications
MONITOR_ALERT_API_TOKEN=轮换后的消息平台Bearer密钥
```

`MONITOR_ALERT_API_URL` 应设置为 `https://messenger.zufedfc.edu.cn/api/v1/notifications`。升级旧部署时务必替换 `.env` 中的旧地址，然后运行 `php artisan config:cache`。管理员在 `/admin/recipients` 添加、修改、停用或重新启用学工号；邮箱选填，不填则由消息平台按学工号查找，填写则覆盖平台快照中的邮箱。学工号必须存在于平台每日同步的人员快照。所有已启用人员接收全部服务通知；事件发生时固定收件人快照，之后更改或停用名单不影响已有邮件。每人独立保存提交编号、平台任务 ID、状态和错误。消息平台自己的邮件队列 worker 必须运行；平台返回 `accepted` 表示 SMTP 已接受，不保证进入收件箱。

网络超时和限流会用原 `request_id` 延迟重试，最多 24 小时。鉴权、参数和平台最终投递失败保留在 `monitor_alerts` 表及 Laravel 日志中，需人工处理。服务恢复前尚未提交的故障邮件会被取消，其对应恢复邮件也取消。收件人配置缺失或无效时，事件保留配置错误记录，不发邮件。

管理员可在 `/admin/recipients` 对单人发送明确标注“测试”的邮件，每人每 5 分钟最多一次。后台显示该人最近一次测试邮件的任务状态与错误。也可运行 `php artisan monitor:test-email` 为全部已启用人员创建测试邮件，再运行 `php artisan monitor:dispatch-alerts`；测试事件不出现在公开异常记录中。等待消息平台异步处理后，再运行派发命令查询最终状态，并确认目标收件箱收到邮件。测试实际服务故障请使用模拟目标，不要中断生产服务。

## 判定与记录

- 探测跟随最多 5 次跳转；最终 HTTP 2xx 为正常，HTTPS 保留证书校验，每项请求最多等待 8 秒。
- 首次失败显示“可能异常／待复核”；连续两次定时失败确认故障。一次成功恢复。探测中断超过 5 分钟时，未确认的失败次数从下次探测重新计算。
- 页面每 2 分钟读取一次已有结果，不允许访客触发探测。可用率仅按实际采样结果计算，页面标明采样缺口；公开页面不展示底层连接异常原文。
- 探测记录保留 30 天；MySQL 中的故障与邮件记录应纳入现有每日备份。

## 验证

```bash
php artisan test
composer types:check
npm run check
npm run types:check
npm run build
```

生产验收检查三项服务和消息平台的网络连通性、cron 重启后恢复、24 小时采样连续性，以及带“测试”标记邮件的最终投递状态。

### 登录后出现 419 Page Expired

419 表示登录提交时无法验证同一会话的 CSRF 令牌。应用在纯 HTTP 下也应能保持会话；如果 HTTPS 尚未启用，不能仅凭浏览器显示“不安全”就判定这是 419 的原因。先在浏览器开发者工具中确认 GET `/login` 返回的会话 Cookie 被保存，并在 POST `/login` 时原样送回。如果 HTTP 上意外下发 `Secure` Cookie，检查 Nginx／PHP 的 `HTTPS` 参数和受信任代理的 `X-Forwarded-Proto`：HTTP 请求不能被错误标为 HTTPS。也检查请求和提交是否使用同一域名。

准备启用 HTTPS 时，先让 `curl -I https://status.zufedfc.edu.cn/login` 能正常验证证书，再让 `curl -I http://status.zufedfc.edu.cn/login` 跳转到 HTTPS，最后设置 `SESSION_SECURE_COOKIE=true`。若使用反向代理，检查上述 `TRUSTED_PROXIES` 和 `X-Forwarded-Proto`。不要关闭 CSRF 中间件。

再检查服务器 `.env` 的 `APP_URL`、`SESSION_DRIVER`、`SESSION_DOMAIN`、`SESSION_SECURE_COOKIE`，以及 `php artisan config:show session` 中的实际生效值。使用数据库会话时，确认 `sessions` 表存在且 Web 用户可读写；多个应用实例必须使用同一数据库和稳定的 `APP_KEY`。修正后执行 `php artisan optimize:clear && php artisan config:cache`，清除浏览器中该站点的旧 Cookie，再从 HTTPS 登录页重试。不要把 `.env`、`APP_KEY` 或会话 Cookie 发给他人。
