<?php

return [
    'admin_emails' => env('MONITOR_ADMIN_EMAILS', ''),
    'redirect_hosts' => env('MONITOR_ALLOWED_REDIRECT_HOSTS', ''),
    'allowed_ports' => env('MONITOR_ALLOWED_PORTS', '80,443,8080,8443'),
    'alerts' => [
        'api_url' => env('MONITOR_ALERT_API_URL'),
        'api_token' => env('MONITOR_ALERT_API_TOKEN'),
        'recipients' => env('MONITOR_ALERT_RECIPIENTS'),
    ],
    'services' => [
        [
            'key' => 'portal',
            'name' => '学校官网',
            'description' => '学校主页与公共信息入口',
            'url' => 'https://www.zufedfc.edu.cn',
        ],
        [
            'key' => 'party',
            'name' => '党建平台',
            'description' => '党建学习与服务入口',
            'url' => 'https://party.zufedfc.edu.cn',
        ],
        [
            'key' => 'webvpn',
            'name' => 'WebVPN',
            'description' => '校外访问校内资源入口',
            'url' => 'http://webvpn.zufedfc.edu.cn/',
        ],
    ],
];
