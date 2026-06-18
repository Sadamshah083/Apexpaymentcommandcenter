<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class QueuePoolCommand extends Command
{
    protected $signature = 'queue:pool
                            {--workers= : Number of parallel queue workers (default: QUEUE_WORKERS / 2)}
                            {--connection= : Queue connection name}
                            {--queue= : Comma-separated queue names}
                            {--tries=3 : Job tries before marking failed}
                            {--timeout=0 : Max seconds per job (0 = unlimited)}
                            {--sleep=1 : Seconds to sleep when the queue is empty}';

    protected $description = 'Run multiple queue workers in parallel so leads enrich concurrently';

    /** @var array<int, Process> */
    protected array $processes = [];

    public function handle(): int
    {
        $workers = (int) ($this->option('workers') ?: config('queue.workers', 2));
        $workers = max(1, min($workers, 32));

        if (config('database.default') === 'sqlite' && ! $this->option('workers') && $workers > 1) {
            $this->warn('SQLite detected: using 1 queue worker to reduce "database is locked" errors.');
            $this->line('Set QUEUE_WORKERS=2 or use --workers=2 only if you accept occasional lock retries.');
            $this->line('For heavy pipelines, use MySQL/PostgreSQL or keep QUEUE_WORKERS=1.');
            $workers = 1;
        }

        $connection = $this->option('connection') ?: (string) config('queue.default', 'database');
        $tries = (int) $this->option('tries');
        $timeout = (int) $this->option('timeout');
        $sleep = (int) $this->option('sleep');

        $this->info("Starting {$workers} parallel queue workers on [{$connection}]…");
        $this->line('Each worker processes one lead at a time; more workers = more leads in parallel.');
        $this->newLine();

        for ($i = 1; $i <= $workers; $i++) {
            $this->startWorker($i, $connection, $tries, $timeout, $sleep);
        }

        $this->trap($this->stopSignals(), function () {
            $this->newLine();
            $this->warn('Stopping queue pool…');
            $this->stopWorkers();
            exit(self::SUCCESS);
        });

        while (true) {
            foreach ($this->processes as $number => $process) {
                if (! $process->isRunning()) {
                    $this->warn("Worker {$number} exited. Restarting…");
                    $this->startWorker(
                        $number,
                        $connection,
                        $tries,
                        $timeout,
                        $sleep
                    );
                }
            }

            usleep(500_000);
        }
    }

    /**
     * @return array<int, int>
     */
    protected function stopSignals(): array
    {
        $signals = [];

        if (defined('SIGINT')) {
            $signals[] = SIGINT;
        }

        if (defined('SIGTERM')) {
            $signals[] = SIGTERM;
        }

        return $signals;
    }

    protected function startWorker(int $number, string $connection, int $tries, int $timeout, int $sleep): void
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            $connection,
            "--name=worker-{$number}",
            "--tries={$tries}",
            "--timeout={$timeout}",
            "--sleep={$sleep}",
        ];

        if ($queue = $this->option('queue')) {
            $command[] = "--queue={$queue}";
        }

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->start(function ($type, $buffer) use ($number) {
            $prefix = "<fg=gray>[worker-{$number}]</>";
            $lines = preg_split("/\r\n|\n|\r/", rtrim($buffer)) ?: [];

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }

                $this->output->writeln("{$prefix} {$line}");
            }
        });

        $this->processes[$number] = $process;
    }

    protected function stopWorkers(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(3, defined('SIGINT') ? SIGINT : null);
            }
        }

        $this->processes = [];
    }
}
