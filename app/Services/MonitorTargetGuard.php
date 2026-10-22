<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class MonitorTargetGuard
{
    public function normalize(string $url, bool $redirect = false): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('请输入完整有效的 HTTP 或 HTTPS 地址。');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('地址不能包含账号、密码或片段。');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('只允许 HTTP 或 HTTPS 地址。');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ports = array_map('intval', explode(',', (string) config('monitors.allowed_ports', '80,443,8080,8443')));
        if (! in_array($port, $ports, true)) {
            throw new InvalidArgumentException('该端口不在允许列表中。');
        }

        $schoolHost = $host === 'zufedfc.edu.cn' || str_ends_with($host, '.zufedfc.edu.cn');
        $extraHosts = array_filter(array_map('trim', explode(',', strtolower((string) config('monitors.redirect_hosts', '')))));
        $allowedHost = $schoolHost || $this->isCampusIp($host)
            || ($redirect && in_array($host, $extraHosts, true));
        if (! $allowedHost) {
            throw new InvalidArgumentException('只允许校内域名、10.0.0.0/8 地址或已批准的跳转域名。');
        }

        $authority = $host;
        if ($port !== ($scheme === 'https' ? 443 : 80)) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @return array<int, mixed> */
    public function curlOptions(string $url): array
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('探测服务器缺少 PHP cURL 扩展。');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            throw new InvalidArgumentException('探测地址无效。');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveAddresses($host);
        if ($addresses === []) {
            throw new RuntimeException('域名解析失败。');
        }

        foreach ($addresses as $address) {
            if (! $this->isSafeAddress($address)) {
                throw new RuntimeException('域名解析到了不允许访问的地址。');
            }
        }

        $target = $addresses[0];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [];
        }

        $pinned = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$target.']' : $target;

        return [CURLOPT_RESOLVE => [$host.':'.$port.':'.$pinned]];
    }

    /** @return list<string> */
    public function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isCampusIp(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && str_starts_with($address, '10.');
    }

    private function isSafeAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (str_starts_with(strtolower($address), '::ffff:')) {
            return false;
        }

        if (in_array($address, $this->localAddresses(), true)) {
            return false;
        }

        return $this->isCampusIp($address)
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return list<string> */
    private function localAddresses(): array
    {
        $addresses = ['127.0.0.1', '::1'];
        foreach (net_get_interfaces() ?: [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                if (isset($unicast['address'])) {
                    $addresses[] = $unicast['address'];
                }
            }
        }

        return $addresses;
    }
}
