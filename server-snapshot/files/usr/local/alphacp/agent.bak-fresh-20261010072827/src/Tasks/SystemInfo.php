<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * system.info — hostname, kernel, CPU, memory, swap, load, disk, uptime.
 * Read-only. Reads /proc directly (no exec) so it works even when the box is
 * under heavy load. This is the data source for the panel dashboard later.
 */
final class SystemInfo implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $ctx->log->info('Collecting system facts');

        $mem  = $this->meminfo();
        $disk = $this->disk();

        $result = [
            'hostname'    => gethostname() ?: 'unknown',
            'kernel'      => php_uname('r'),
            'os'          => $this->osPrettyName(),
            'arch'        => php_uname('m'),
            'cpu_cores'   => $this->cpuCores(),
            'load'        => array_map(static fn ($v) => round((float) $v, 2), sys_getloadavg() ?: [0, 0, 0]),
            'uptime_sec'  => $this->uptimeSeconds(),
            'memory'      => $mem,
            'swap'        => $this->swapinfo(),
            'disk'        => $disk,
            'php_version' => PHP_VERSION,
            'agent'       => [
                'version' => ACP_AGENT_VERSION,
                'pid'     => getmypid(),
            ],
            'collected_at' => gmdate('c'),
        ];

        $ctx->log->info(sprintf(
            'mem %.0f%% used (%d/%d MB) · disk %.0f%% used (%d/%d GB) · load %.2f',
            $mem['used_pct'],
            $mem['used_mb'],
            $mem['total_mb'],
            $disk['used_pct'],
            $disk['used_gb'],
            $disk['total_gb'],
            $result['load'][0],
        ));

        return $result;
    }

    /** @return array{total_mb:int,used_mb:int,available_mb:int,used_pct:float,buffers_mb:int,cached_mb:int} */
    private function meminfo(): array
    {
        $kv = [];
        foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m) === 1) {
                $kv[$m[1]] = (int) $m[2]; // kB
            }
        }

        $toMb  = static fn (int $kb): int => (int) round($kb / 1024);
        $total = $kv['MemTotal'] ?? 0;
        $avail = $kv['MemAvailable'] ?? ($kv['MemFree'] ?? 0);
        $used  = max(0, $total - $avail);

        return [
            'total_mb'     => $toMb($total),
            'used_mb'      => $toMb($used),
            'available_mb' => $toMb($avail),
            'buffers_mb'   => $toMb($kv['Buffers'] ?? 0),
            'cached_mb'    => $toMb($kv['Cached'] ?? 0),
            'used_pct'     => $total > 0 ? round($used / $total * 100, 1) : 0.0,
        ];
    }

    /** @return array{total_mb:int,used_mb:int} */
    private function swapinfo(): array
    {
        $kv = [];
        foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^(Swap\w+):\s+(\d+)/', $line, $m) === 1) {
                $kv[$m[1]] = (int) $m[2];
            }
        }
        $total = (int) round(($kv['SwapTotal'] ?? 0) / 1024);
        $free  = (int) round(($kv['SwapFree'] ?? 0) / 1024);

        return ['total_mb' => $total, 'used_mb' => max(0, $total - $free)];
    }

    /** @return array{total_gb:float,used_gb:float,free_gb:float,used_pct:float,mount:string} */
    private function disk(): array
    {
        $total = (float) @disk_total_space('/');
        $free  = (float) @disk_free_space('/');
        $used  = max(0.0, $total - $free);
        $gb    = static fn (float $b): float => round($b / 1024 ** 3, 1);

        return [
            'mount'    => '/',
            'total_gb' => $gb($total),
            'used_gb'  => $gb($used),
            'free_gb'  => $gb($free),
            'used_pct' => $total > 0 ? round($used / $total * 100, 1) : 0.0,
        ];
    }

    private function cpuCores(): int
    {
        $info  = @file_get_contents('/proc/cpuinfo') ?: '';
        $count = preg_match_all('/^processor\s*:/m', $info);
        return max(1, (int) $count);
    }

    private function uptimeSeconds(): int
    {
        $raw = @file_get_contents('/proc/uptime') ?: '0';
        return (int) (float) strtok($raw, ' ');
    }

    private function osPrettyName(): string
    {
        foreach (@file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'PRETTY_NAME=')) {
                return trim(explode('=', $line, 2)[1], '"');
            }
        }
        return PHP_OS_FAMILY;
    }
}
