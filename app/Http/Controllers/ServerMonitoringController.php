<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServerMonitoringController extends Controller
{
    public function index(Request $request)
    {
        // 1. OS & Basic Info
        $os = PHP_OS_FAMILY;
        $phpVersion = PHP_VERSION;
        $laravelVersion = app()->version();
        $uptime = $this->getUptime($os);

        // 2. CPU Usage
        $cpu = $this->getCpuUsage($os);

        // 3. RAM Usage
        $ram = $this->getRamUsage($os);

        // 4. Disk Usage
        $disk = $this->getDiskUsage();

        // 5. Database Status
        $dbStatus = 'Connected';
        $connection = config('database.default');
        $dbName = config("database.connections.{$connection}.database", '—');
        if ($connection === 'sqlite' && $dbName) {
            $dbName = basename(str_replace('\\', '/', $dbName));
        }
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $dbStatus = 'Disconnected: ' . $e->getMessage();
        }

        // 6. Queue Metrics
        $queueActive = 0;
        $queueFailed = 0;
        if ($dbStatus === 'Connected') {
            try {
                $queueActive = DB::table('jobs')->count();
                $queueFailed = DB::table('failed_jobs')->count();
            } catch (\Exception $e) {
                Log::warning('Could not count queue jobs: ' . $e->getMessage());
            }
        }

        return view('server-monitoring.index', [
            'os' => $os,
            'phpVersion' => $phpVersion,
            'laravelVersion' => $laravelVersion,
            'uptime' => $uptime,
            'cpu' => $cpu,
            'ram' => $ram,
            'disk' => $disk,
            'dbStatus' => $dbStatus,
            'dbName' => $dbName,
            'queueActive' => $queueActive,
            'queueFailed' => $queueFailed,
        ]);
    }

    private function getUptime(string $os): string
    {
        try {
            if ($os === 'Windows') {
                if (function_exists('shell_exec')) {
                    $out = shell_exec('wmic path Win32_OperatingSystem get LastBootUpTime');
                    if ($out) {
                        $lines = array_filter(array_map('trim', explode("\n", $out)));
                        if (count($lines) >= 2) {
                            $bootTimeStr = $lines[1]; // Format: YYYYMMDDHHMMSS.UUUUUU+ZZZ
                            $year = substr($bootTimeStr, 0, 4);
                            $month = substr($bootTimeStr, 4, 2);
                            $day = substr($bootTimeStr, 6, 2);
                            $hour = substr($bootTimeStr, 8, 2);
                            $min = substr($bootTimeStr, 10, 2);
                            $sec = substr($bootTimeStr, 12, 2);
                            $bootTime = strtotime("$year-$month-$day $hour:$min:$sec");
                            $diff = time() - $bootTime;
                            return $this->formatSecondsToInterval($diff);
                        }
                    }
                }
            } else {
                // Linux / Unix
                if (is_readable('/proc/uptime')) {
                    $str = file_get_contents('/proc/uptime');
                    $num = (float) explode(' ', $str)[0];
                    return $this->formatSecondsToInterval((int) $num);
                }
                if (function_exists('shell_exec')) {
                    $out = shell_exec('uptime');
                    if ($out) {
                        return trim($out);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Uptime error: ' . $e->getMessage());
        }

        return 'Unknown Uptime';
    }

    private function getCpuUsage(string $os): array
    {
        $percentage = 0.0;
        $cores = 1;

        try {
            if ($os === 'Windows') {
                if (function_exists('shell_exec')) {
                    $out = shell_exec('wmic cpu get LoadPercentage');
                    if ($out) {
                        $lines = array_values(array_filter(array_map('trim', explode("\n", $out))));
                        if (count($lines) >= 2) {
                            $percentage = (float) $lines[1];
                        }
                    }
                    $coresOut = shell_exec('wmic cpu get NumberOfCores');
                    if ($coresOut) {
                        $coresLines = array_values(array_filter(array_map('trim', explode("\n", $coresOut))));
                        if (count($coresLines) >= 2) {
                            $cores = (int) $coresLines[1];
                        }
                    }
                }
            } else {
                // Linux
                if (function_exists('sys_getloadavg')) {
                    $load = sys_getloadavg();
                    if ($load && isset($load[0])) {
                        // Estimate CPU percentage from 1-min load average divided by cores
                        $cores = $this->getLinuxCoresCount();
                        $percentage = min(100.0, ($load[0] / $cores) * 100.0);
                    }
                } else if (is_readable('/proc/stat')) {
                    $stat1 = file_get_contents('/proc/stat');
                    usleep(100000); // 100ms sleep
                    $stat2 = file_get_contents('/proc/stat');
                    $percentage = $this->calculateCpuFromProcStat($stat1, $stat2);
                    $cores = $this->getLinuxCoresCount();
                }
            }
        } catch (\Exception $e) {
            Log::warning('CPU Usage error: ' . $e->getMessage());
        }

        return [
            'percentage' => round($percentage, 1),
            'cores' => $cores,
        ];
    }

    private function getLinuxCoresCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]) ?: 1;
        }
        return 1;
    }

    private function calculateCpuFromProcStat(string $stat1, string $stat2): float
    {
        $get_cpu = function ($stat) {
            $lines = explode("\n", $stat);
            $cpu = explode(' ', preg_replace('!\s+!', ' ', trim($lines[0])));
            array_shift($cpu);
            return array_map('intval', $cpu);
        };

        $cpu1 = $get_cpu($stat1);
        $cpu2 = $get_cpu($stat2);

        if (count($cpu1) < 4 || count($cpu2) < 4) {
            return 0.0;
        }

        $total1 = array_sum($cpu1);
        $total2 = array_sum($cpu2);

        $idle1 = $cpu1[3];
        $idle2 = $cpu2[3];

        $diffTotal = $total2 - $total1;
        $diffIdle = $idle2 - $idle1;

        if ($diffTotal === 0) {
            return 0.0;
        }

        return (($diffTotal - $diffIdle) / $diffTotal) * 100.0;
    }

    private function getRamUsage(string $os): array
    {
        $total = 0;
        $used = 0;
        $free = 0;

        try {
            if ($os === 'Windows') {
                if (function_exists('shell_exec')) {
                    $outTotal = shell_exec('wmic OS get TotalVisibleMemorySize');
                    $outFree = shell_exec('wmic OS get FreePhysicalMemory');
                    
                    $totalKb = 0;
                    $freeKb = 0;

                    if ($outTotal) {
                        $lines = array_values(array_filter(array_map('trim', explode("\n", $outTotal))));
                        if (count($lines) >= 2) {
                            $totalKb = (float) $lines[1];
                        }
                    }
                    if ($outFree) {
                        $lines = array_values(array_filter(array_map('trim', explode("\n", $outFree))));
                        if (count($lines) >= 2) {
                            $freeKb = (float) $lines[1];
                        }
                    }

                    if ($totalKb > 0) {
                        $total = $totalKb * 1024; // Convert to bytes
                        $free = $freeKb * 1024;
                        $used = $total - $free;
                    }
                }
            } else {
                // Linux
                if (is_readable('/proc/meminfo')) {
                    $meminfo = file_get_contents('/proc/meminfo');
                    preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', $meminfo, $matchesTotal);
                    preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $meminfo, $matchesAvail);
                    
                    if (isset($matchesTotal[1])) {
                        $total = ((int) $matchesTotal[1]) * 1024;
                        if (isset($matchesAvail[1])) {
                            $avail = ((int) $matchesAvail[1]) * 1024;
                            $used = $total - $avail;
                            $free = $avail;
                        } else {
                            // Fallback
                            preg_match('/^MemFree:\s+(\d+)\s+kB$/m', $meminfo, $matchesFree);
                            $free = isset($matchesFree[1]) ? ((int) $matchesFree[1]) * 1024 : 0;
                            $used = $total - $free;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('RAM Usage error: ' . $e->getMessage());
        }

        $percentage = $total > 0 ? ($used / $total) * 100 : 0;

        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'free' => $this->formatBytes($free),
            'percentage' => round($percentage, 1),
        ];
    }

    private function getDiskUsage(): array
    {
        $total = 0;
        $free = 0;
        $used = 0;
        $percentage = 0.0;

        try {
            $total = @disk_total_space('/');
            $free = @disk_free_space('/');
            
            // Fallback for Windows or absolute path matching
            if ($total === false || $total === 0) {
                $total = @disk_total_space('C:');
                $free = @disk_free_space('C:');
            }

            if ($total > 0) {
                $used = $total - $free;
                $percentage = ($used / $total) * 100;
            }
        } catch (\Exception $e) {
            Log::warning('Disk Usage error: ' . $e->getMessage());
        }

        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'free' => $this->formatBytes($free),
            'percentage' => round($percentage, 1),
        ];
    }

    private function formatSecondsToInterval(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $mins = floor(($seconds % 3600) / 60);

        $out = [];
        if ($days > 0) {
            $out[] = "$days days";
        }
        if ($hours > 0) {
            $out[] = "$hours hours";
        }
        if ($mins > 0) {
            $out[] = "$mins mins";
        }

        return count($out) > 0 ? implode(', ', $out) : 'Less than a minute';
    }

    private function formatBytes(float $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
