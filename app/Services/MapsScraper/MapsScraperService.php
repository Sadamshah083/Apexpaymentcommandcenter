<?php

namespace App\Services\MapsScraper;

use App\Models\MapsScrapeJob;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MapsScraperService
{
    public function __construct(
        protected MapsScraperExcelExporter $exporter,
    ) {}

    public function scraperRoot(): string
    {
        return rtrim((string) config('maps_scraper.path'), DIRECTORY_SEPARATOR);
    }

    public function assertReady(): void
    {
        // Default ON unless explicitly disabled in config/env.
        $enabled = config('maps_scraper.enabled');
        if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'false') {
            throw new RuntimeException('Maps scraper is disabled.');
        }

        $root = $this->scraperRoot();
        if (! is_dir($root) || ! is_file($root.DIRECTORY_SEPARATOR.'apex_bridge.py')) {
            throw new RuntimeException('Maps scraper tools are missing. Expected tools/google-maps-scraper.');
        }

        $python = $this->resolvePython();
        if ($python === '') {
            throw new RuntimeException(
                'Python for Maps scraper was not found. Install tools/google-maps-scraper/.venv or set MAPS_SCRAPER_PYTHON.'
            );
        }
    }

    /**
     * Cities for a US state (cached Wikipedia list under tools/google-maps-scraper/data/cities).
     *
     * @return list<string>
     */
    public function citiesForState(string $stateName): array
    {
        $stateName = trim($stateName);
        if ($stateName === '') {
            return [];
        }

        $root = $this->scraperRoot();
        $slug = strtolower(str_replace(' ', '_', $stateName));
        $path = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'cities'.DIRECTORY_SEPARATOR.$slug.'_cities.txt';

        if (is_file($path)) {
            $cities = $this->readCitiesFile($path);
            if ($cities !== []) {
                return $cities;
            }
        }

        $python = $this->resolvePython();
        $script = $root.DIRECTORY_SEPARATOR.'fetch_state_cities.py';
        if ($python !== '' && is_file($script)) {
            try {
                Process::path($root)
                    ->timeout(90)
                    ->run([
                        $python,
                        $script,
                        '--state', $stateName,
                        '--cities-dir', $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'cities',
                        '--states-file', $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'us_states.json',
                    ]);
            } catch (Throwable) {
                // Fall through to cache / fallback.
            }

            if (is_file($path)) {
                $cities = $this->readCitiesFile($path);
                if ($cities !== []) {
                    return $cities;
                }
            }
        }

        return $this->fallbackCitiesForState($stateName);
    }

    /**
     * @return list<string>
     */
    protected function readCitiesFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $cities = [];
        $seen = [];
        foreach ($lines as $line) {
            $name = trim((string) $line);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cities[] = $name;
        }

        sort($cities, SORT_NATURAL | SORT_FLAG_CASE);

        return $cities;
    }

    /**
     * @return list<string>
     */
    protected function fallbackCitiesForState(string $stateName): array
    {
        $map = (array) config('maps_scraper.fallback_cities', []);
        $cities = $map[$stateName] ?? [];
        if (! is_array($cities)) {
            return [];
        }

        $cities = array_values(array_filter(array_map('strval', $cities)));
        sort($cities, SORT_NATURAL | SORT_FLAG_CASE);

        return $cities;
    }

    /**
     * Prefer configured binary; if missing, fall back to venv or system python3.
     */
    public function resolvePython(): string
    {
        $candidates = [];
        $configured = trim((string) config('maps_scraper.python', ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $root = $this->scraperRoot();
        $candidates[] = $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python';
        $candidates[] = $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'Scripts'.DIRECTORY_SEPARATOR.'python.exe';
        $candidates[] = '/usr/bin/python3';
        $candidates[] = 'python3';
        $candidates[] = 'python';

        foreach ($candidates as $bin) {
            if ($this->pythonBinaryWorks($bin)) {
                return $bin;
            }
        }

        return '';
    }

    protected function pythonBinaryWorks(string $bin): bool
    {
        if ($bin === '') {
            return false;
        }

        // Absolute path must exist.
        if (str_contains($bin, DIRECTORY_SEPARATOR) || str_starts_with($bin, '/') || preg_match('/^[A-Za-z]:\\\\/', $bin)) {
            if (! is_file($bin) && ! is_executable($bin)) {
                // On Windows, is_executable is unreliable; is_file is enough.
                if (! is_file($bin)) {
                    return false;
                }
            }
        }

        try {
            $result = Process::timeout(15)->run([$bin, '-c', 'import sys; print(sys.executable)']);

            return $result->successful() && trim($result->output()) !== '';
        } catch (Throwable) {
            return false;
        }
    }

    public function run(MapsScrapeJob $job): void
    {
        $this->assertReady();

        $job->update([
            'status' => 'running',
            'progress_pct' => 5,
            'progress_message' => 'Launching Google Maps scraper…',
            'error_message' => null,
            'meta' => array_merge($job->meta ?? [], [
                'live_logs' => array_values(array_slice(array_merge(
                    is_array($job->meta['live_logs'] ?? null) ? $job->meta['live_logs'] : [],
                    ['['.now()->format('H:i:s').'] Worker connected', '['.now()->format('H:i:s').'] Launching Google Maps scraper…']
                ), -80)),
            ]),
        ]);

        $disk = Storage::disk((string) config('maps_scraper.storage_disk', 'local'));
        $baseRel = trim((string) config('maps_scraper.storage_dir', 'maps-scraper'), '/').'/job-'.$job->id;
        $disk->makeDirectory($baseRel);

        $csvRel = $baseRel.'/results.csv';
        $progressRel = $baseRel.'/progress.json';
        $csvAbs = $disk->path($csvRel);
        $progressAbs = $disk->path($progressRel);

        $job->update([
            'csv_path' => $csvRel,
            'meta' => array_merge($job->meta ?? [], [
                'progress_path' => $progressRel,
            ]),
        ]);

        try {
            $this->executeBridge($job, $csvAbs, $progressAbs);
            $this->syncProgressFromFile($job, $progressAbs);

            if (! is_file($csvAbs)) {
                throw new RuntimeException('Scraper finished without producing a CSV file.');
            }

            $job->update([
                'progress_pct' => 85,
                'progress_message' => 'Building Excel files by area code…',
            ]);

            $rows = $this->exporter->readCsv($csvAbs);
            if ($job->small_business_only) {
                $rows = $this->exporter->filterSmallBusinesses($rows);
            }

            if ($rows === []) {
                throw new RuntimeException('No small-business phone numbers were found to export.');
            }

            $exportDir = $disk->path($baseRel.'/export');
            $result = $this->exporter->exportGroupedByAreaCode(
                $rows,
                $exportDir,
                'maps_leads_by_area_code_'.$job->id
            );

            $zipRel = $baseRel.'/export/'.basename($result['zip_path']);
            $job->update([
                'status' => 'completed',
                'progress_pct' => 100,
                'progress_message' => sprintf(
                    'Done — %d leads in %d Excel files (by area code)',
                    $result['row_count'],
                    $result['file_count']
                ),
                'row_count' => $result['row_count'],
                'file_count' => $result['file_count'],
                'export_zip_path' => $zipRel,
                'meta' => array_merge($job->meta ?? [], [
                    'area_code_groups' => $result['groups'],
                ]),
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'progress_message' => 'Failed',
                'error_message' => Str::limit($e->getMessage(), 2000),
            ]);
            throw $e;
        }
    }

    /**
     * Convert an existing scraper CSV into area-code Excel ZIP (no Playwright).
     */
    public function exportCsvOnly(MapsScrapeJob $job, string $absoluteCsvPath): void
    {
        $disk = Storage::disk((string) config('maps_scraper.storage_disk', 'local'));
        $baseRel = trim((string) config('maps_scraper.storage_dir', 'maps-scraper'), '/').'/job-'.$job->id;
        $disk->makeDirectory($baseRel);

        $csvRel = $baseRel.'/results.csv';
        $disk->put($csvRel, file_get_contents($absoluteCsvPath) ?: '');

        $job->update([
            'status' => 'running',
            'csv_path' => $csvRel,
            'progress_pct' => 50,
            'progress_message' => 'Building Excel files by area code…',
        ]);

        $rows = $this->exporter->readCsv($disk->path($csvRel));
        if ($job->small_business_only) {
            $rows = $this->exporter->filterSmallBusinesses($rows);
        }

        $exportDir = $disk->path($baseRel.'/export');
        $result = $this->exporter->exportGroupedByAreaCode(
            $rows,
            $exportDir,
            'maps_leads_by_area_code_'.$job->id
        );

        $job->update([
            'status' => 'completed',
            'progress_pct' => 100,
            'progress_message' => sprintf(
                'Done — %d leads in %d Excel files (by area code)',
                $result['row_count'],
                $result['file_count']
            ),
            'row_count' => $result['row_count'],
            'file_count' => $result['file_count'],
            'export_zip_path' => $baseRel.'/export/'.basename($result['zip_path']),
            'meta' => array_merge($job->meta ?? [], [
                'area_code_groups' => $result['groups'],
                'source' => 'csv_upload',
            ]),
        ]);
    }

    protected function executeBridge(MapsScrapeJob $job, string $csvAbs, string $progressAbs): void
    {
        $python = $this->resolvePython();
        if ($python === '') {
            throw new RuntimeException(
                'Python binary not found. Expected tools/google-maps-scraper/.venv/bin/python or system python3.'
            );
        }

        $root = $this->scraperRoot();
        $script = $root.DIRECTORY_SEPARATOR.'apex_bridge.py';

        // 0 or negative = unlimited (scrape until Maps has no more listings).
        $requested = (int) $job->per_search;
        $limit = $requested <= 0 ? 1_000_000 : max(1, $requested);

        $cmd = [
            $python,
            $script,
            '--job-mode', $job->job_mode,
            '--output', $csvAbs,
            '--progress-file', $progressAbs,
            '--individual-only',
        ];

        if ($job->job_mode === 'quick') {
            $cmd = array_merge($cmd, [
                '--search', (string) $job->search_query,
                '--total', (string) $limit,
            ]);
        } elseif ($job->job_mode === 'state') {
            $cmd = array_merge($cmd, [
                '--state', (string) $job->state,
                '--business', (string) $job->business,
                '--per-city', (string) $limit,
                '--scrape-mode', (string) ($job->scrape_mode ?: 'city'),
            ]);
        } else {
            throw new RuntimeException('Unsupported scrape mode: '.$job->job_mode);
        }

        $job->update([
            'meta' => array_merge($job->meta ?? [], [
                'python_bin' => $python,
                'result_limit' => $requested <= 0 ? 'unlimited' : $limit,
                'live_logs' => array_values(array_slice(array_merge(
                    is_array($job->meta['live_logs'] ?? null) ? $job->meta['live_logs'] : [],
                    [
                        '['.now()->format('H:i:s').'] Using Python: '.$python,
                        '['.now()->format('H:i:s').'] Result limit: '.($requested <= 0 ? 'unlimited (all available)' : (string) $limit),
                    ]
                ), -80)),
            ]),
        ]);

        $env = $this->bridgeEnv();

        try {
            $process = Process::path($root)
                ->timeout((int) config('maps_scraper.timeout_seconds', 7200))
                ->env($env)
                ->start($cmd);

            while ($process->running()) {
                $this->syncProgressFromFile($job->fresh(), $progressAbs);
                usleep(1_500_000);
            }

            $result = $process->wait();
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException('Maps scrape timed out. Try a smaller per-city limit or quick mode.', 0, $e);
        }

        if (! $result->successful()) {
            $stderr = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException($stderr !== '' ? $stderr : 'Maps scraper process failed.');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function bridgeEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_scalar($value)) {
                $env[(string) $key] = (string) $value;
            }
        }

        $env['MAPS_SCRAPER_HEADLESS'] = config('maps_scraper.headless') ? '1' : '0';

        if (empty($env['PATH']) && ! empty($_SERVER['PATH'])) {
            $env['PATH'] = (string) $_SERVER['PATH'];
        }

        // Linux server Playwright paths only — do not force on Windows local.
        if (PHP_OS_FAMILY !== 'Windows') {
            $env['HOME'] = $env['HOME'] ?? '/var/www';
            $env['PLAYWRIGHT_BROWSERS_PATH'] = $env['PLAYWRIGHT_BROWSERS_PATH']
                ?? '/var/www/.cache/ms-playwright';
        }

        $chrome = config('maps_scraper.chrome_path');
        if (filled($chrome)) {
            $env['MAPS_SCRAPER_CHROME_PATH'] = (string) $chrome;
        }

        return $env;
    }

    public function readLiveProgress(MapsScrapeJob $job): array
    {
        $progressRel = $job->meta['progress_path'] ?? null;
        if (! is_string($progressRel) || $progressRel === '') {
            return [];
        }

        $disk = Storage::disk((string) config('maps_scraper.storage_disk', 'local'));
        if (! $disk->exists($progressRel)) {
            return [];
        }

        $payload = json_decode((string) $disk->get($progressRel), true);

        return is_array($payload) ? $payload : [];
    }

    protected function syncProgressFromFile(MapsScrapeJob $job, string $progressAbs): void
    {
        if (! is_file($progressAbs)) {
            return;
        }

        $payload = json_decode((string) file_get_contents($progressAbs), true);
        if (! is_array($payload)) {
            return;
        }

        if (($payload['status'] ?? '') === 'failed') {
            throw new RuntimeException((string) ($payload['message'] ?? 'Scraper reported failure.'));
        }

        $logs = $job->meta['live_logs'] ?? [];
        if (! is_array($logs)) {
            $logs = [];
        }
        $message = (string) ($payload['message'] ?? 'Scraping…');
        if ($message !== '' && (empty($logs) || end($logs) !== $message)) {
            $logs[] = '['.now()->format('H:i:s').'] '.$message;
            $logs = array_slice($logs, -80);
        }

        $job->update([
            'progress_pct' => min(80, max(5, (int) ($payload['percent'] ?? 50))),
            'progress_message' => $message,
            'row_count' => (int) ($payload['rows'] ?? $job->row_count),
            'meta' => array_merge($job->meta ?? [], [
                'live_logs' => $logs,
                'last_progress' => $payload,
            ]),
        ]);
    }
}
