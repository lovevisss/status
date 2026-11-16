<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class GatewayPing
{
    /** @param list<string> $ips
     * @return array<string, array{up: bool, response_ms: int, error: string|null, valid: bool}>
     */
    public function probe(array $ips): array
    {
        $results = [];
        foreach (array_chunk($ips, 8) as $batch) {
            $processes = [];
            foreach ($batch as $ip) {
                $command = PHP_OS_FAMILY === 'Windows'
                    ? ['ping', '-n', '1', '-w', '2000', $ip]
                    : ['ping', '-n', '-c', '1', '-W', '2', $ip];
                $process = new Process($command);
                $process->setTimeout(3);
                $start = microtime(true);
                try {
                    $process->start();
                    $processes[$ip] = [$process, $start];
                } catch (\Throwable $exception) {
                    $results[$ip] = ['up' => false, 'response_ms' => 0, 'error' => 'Ping 命令无法启动', 'valid' => false];
                }
            }
            foreach ($processes as $ip => [$process, $start]) {
                try {
                    $process->wait();
                    $up = $process->isSuccessful();
                    $results[$ip] = [
                        'up' => $up,
                        'response_ms' => (int) round((microtime(true) - $start) * 1000),
                        'error' => $up ? null : 'Ping 无响应',
                        'valid' => $up || $process->getExitCode() === 1,
                    ];
                } catch (\Throwable $exception) {
                    $process->stop();
                    $results[$ip] = ['up' => false, 'response_ms' => 3000, 'error' => 'Ping 执行失败', 'valid' => false];
                }
            }
        }

        return $results;
    }
}
