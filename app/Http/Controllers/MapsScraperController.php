<?php

namespace App\Http\Controllers;

use App\Jobs\RunMapsScrapeJob;
use App\Models\MapsScrapeJob;
use App\Services\MapsScraper\MapsScraperService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MapsScraperController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected MapsScraperService $scraper,
    ) {}

    public function index()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $jobsQuery = MapsScrapeJob::query()->where('workspace_id', $workspace->id);

        $jobs = (clone $jobsQuery)->latest()->paginate(15);

        $stats = [
            'total_jobs' => (clone $jobsQuery)->count(),
            'running' => (clone $jobsQuery)->whereIn('status', ['pending', 'running'])->count(),
            'completed' => (clone $jobsQuery)->where('status', 'completed')->count(),
            'failed' => (clone $jobsQuery)->where('status', 'failed')->count(),
            'leads_saved' => (int) (clone $jobsQuery)->sum('row_count'),
            'files_ready' => (int) (clone $jobsQuery)->sum('file_count'),
        ];

        $activeJob = (clone $jobsQuery)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        $states = $this->usStates();
        $categories = config('maps_scraper.priority_categories', []);
        $defaultState = (string) config('maps_scraper.default_state', 'Georgia');
        $defaultCity = (string) config('maps_scraper.default_city', 'Atlanta');

        $ready = true;
        $readyError = null;
        try {
            $this->scraper->assertReady();
        } catch (\Throwable $e) {
            $ready = false;
            $readyError = $e->getMessage();
        }

        $stalePending = (clone $jobsQuery)
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(3))
            ->exists();

        return view('maps-scraper.index', compact(
            'jobs',
            'states',
            'ready',
            'readyError',
            'stats',
            'activeJob',
            'categories',
            'defaultState',
            'defaultCity',
            'stalePending',
        ));
    }

    public function cities(Request $request)
    {
        $state = trim((string) $request->query('state', ''));
        if ($state === '') {
            return response()->json(['cities' => []]);
        }

        $cities = $this->scraper->citiesForState($state);

        return response()->json([
            'state' => $state,
            'cities' => $cities,
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        try {
            $this->scraper->assertReady();
        } catch (\Throwable $e) {
            return back()->withErrors(['scraper' => $e->getMessage()])->withInput();
        }

        $active = MapsScrapeJob::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($active && ! $request->boolean('allow_parallel')) {
            return back()
                ->withErrors(['scraper' => 'A scrape is already running. Open the active job or wait for it to finish.'])
                ->withInput();
        }

        $validated = $request->validate([
            'job_mode' => ['required', Rule::in(['quick', 'state', 'csv'])],
            'search_query' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'business' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'scrape_mode' => ['nullable', Rule::in(['city', 'grid', 'both'])],
            'per_search' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'run_sync' => ['nullable', 'boolean'],
            'csv_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        // One-click category Start from the priority table.
        if (filled($validated['category'] ?? null)) {
            $state = filled($validated['state'] ?? null)
                ? (string) $validated['state']
                : (string) config('maps_scraper.default_state', 'Georgia');
            $city = filled($validated['city'] ?? null)
                ? (string) $validated['city']
                : (string) config('maps_scraper.default_city', 'Atlanta');
            $category = (string) $validated['category'];
            $validated['job_mode'] = 'quick';
            $validated['search_query'] = sprintf('%s in %s, %s, USA', $category, $city, $state);
            $validated['business'] = $category;
            $validated['state'] = $state;
            // Always collect every listing Maps returns for category Start.
            $validated['per_search'] = 0;
        }

        if ($validated['job_mode'] === 'quick' && blank($validated['search_query'] ?? null)) {
            return back()->withErrors(['search_query' => 'Enter a Maps search query for quick scrape.'])->withInput();
        }

        if ($validated['job_mode'] === 'state') {
            if (blank($validated['state'] ?? null) || blank($validated['business'] ?? null)) {
                return back()->withErrors(['business' => 'State and business type are required.'])->withInput();
            }
        }

        if ($validated['job_mode'] === 'csv' && ! $request->hasFile('csv_file')) {
            return back()->withErrors(['csv_file' => 'Upload a scraper CSV to convert into Excel files.'])->withInput();
        }

        $job = MapsScrapeJob::create([
            'workspace_id' => $workspace->id,
            'user_id' => Auth::id(),
            'job_mode' => $validated['job_mode'],
            'state' => $validated['state'] ?? null,
            'business' => $validated['business'] ?? ($validated['category'] ?? null),
            'search_query' => $validated['search_query'] ?? ($validated['job_mode'] === 'csv' ? 'CSV upload' : null),
            'scrape_mode' => $validated['scrape_mode'] ?? 'city',
            'per_search' => (int) ($validated['per_search'] ?? config('maps_scraper.default_total', 0)),
            'small_business_only' => true,
            'status' => 'pending',
            'progress_message' => 'Queued — starting worker…',
            'meta' => [
                'source_mode' => $validated['job_mode'],
                'city' => $validated['city'] ?? config('maps_scraper.default_city'),
                'category' => $validated['category'] ?? $validated['business'] ?? null,
                'live_logs' => [
                    '['.now()->format('H:i:s').'] Run #queued created',
                    '['.now()->format('H:i:s').'] Source: Google Maps (Playwright, no Places API billing)',
                ],
            ],
        ]);

        if ($validated['job_mode'] === 'csv') {
            $tmp = $request->file('csv_file')->getRealPath();
            try {
                $this->scraper->exportCsvOnly($job, (string) $tmp);
            } catch (\Throwable $e) {
                $job->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'progress_message' => 'Failed',
                ]);

                return redirect()
                    ->route('admin.maps-scraper.show', $job)
                    ->with('error', $e->getMessage());
            }

            return redirect()
                ->route('admin.maps-scraper.show', $job)
                ->with('success', 'Excel files ready — download the area-code ZIP.');
        }

        $this->launchWorker($job, $request->boolean('run_sync') || $validated['job_mode'] === 'quick');

        return redirect()
            ->route('admin.maps-scraper.show', $job)
            ->with('success', 'Maps Lead Scraper started. Live progress updates below.');
    }

    public function show(MapsScrapeJob $mapsScrapeJob)
    {
        $this->ensureJobBelongsToWorkspace($mapsScrapeJob);

        return view('maps-scraper.show', ['job' => $mapsScrapeJob]);
    }

    public function status(MapsScrapeJob $mapsScrapeJob)
    {
        $this->ensureJobBelongsToWorkspace($mapsScrapeJob);
        $mapsScrapeJob->refresh();

        $live = $this->scraper->readLiveProgress($mapsScrapeJob);
        if ($live !== [] && ! $mapsScrapeJob->isComplete()) {
            $mapsScrapeJob->update([
                'progress_pct' => min(80, max(5, (int) ($live['percent'] ?? $mapsScrapeJob->progress_pct))),
                'progress_message' => (string) ($live['message'] ?? $mapsScrapeJob->progress_message),
                'row_count' => max((int) $mapsScrapeJob->row_count, (int) ($live['rows'] ?? 0)),
            ]);
            $mapsScrapeJob->refresh();
        }

        $logs = $mapsScrapeJob->meta['live_logs'] ?? [];
        if (! is_array($logs)) {
            $logs = [];
        }

        return response()->json([
            'status' => $mapsScrapeJob->status,
            'progress_pct' => $mapsScrapeJob->progress_pct,
            'progress_message' => $mapsScrapeJob->progress_message,
            'row_count' => $mapsScrapeJob->row_count,
            'file_count' => $mapsScrapeJob->file_count,
            'error_message' => $mapsScrapeJob->error_message,
            'complete' => $mapsScrapeJob->isComplete(),
            'download_ready' => $mapsScrapeJob->status === 'completed' && filled($mapsScrapeJob->export_zip_path),
            'logs' => array_values($logs),
            'state' => $mapsScrapeJob->state,
            'city' => $mapsScrapeJob->meta['city'] ?? null,
            'category' => $mapsScrapeJob->business ?: ($mapsScrapeJob->meta['category'] ?? null),
            'query' => $mapsScrapeJob->search_query,
        ]);
    }

    public function download(MapsScrapeJob $mapsScrapeJob)
    {
        $this->ensureJobBelongsToWorkspace($mapsScrapeJob);

        if ($mapsScrapeJob->status !== 'completed' || blank($mapsScrapeJob->export_zip_path)) {
            abort(404, 'Export not ready.');
        }

        $disk = Storage::disk((string) config('maps_scraper.storage_disk', 'local'));
        if (! $disk->exists($mapsScrapeJob->export_zip_path)) {
            abort(404, 'Export file missing.');
        }

        return $disk->download(
            $mapsScrapeJob->export_zip_path,
            'maps-leads-area-codes-'.$mapsScrapeJob->id.'.zip'
        );
    }

    protected function launchWorker(MapsScrapeJob $job, bool $preferInline): void
    {
        $jobId = (int) $job->id;

        // Quick scrapes: run after HTTP response so Start always launches without a queue worker.
        // Longer state scrapes: queue when available; also afterResponse fallback for sync queues.
        if ($preferInline || config('queue.default') === 'sync') {
            dispatch(function () use ($jobId) {
                $model = MapsScrapeJob::query()->find($jobId);
                if (! $model || $model->isComplete()) {
                    return;
                }
                try {
                    app(MapsScraperService::class)->run($model);
                } catch (\Throwable) {
                    // MapsScraperService already marks the job failed.
                }
            })->afterResponse();

            return;
        }

        RunMapsScrapeJob::dispatch($jobId);

        // Safety net: if the job is still pending after response, try afterResponse once.
        dispatch(function () use ($jobId) {
            $model = MapsScrapeJob::query()->find($jobId);
            if (! $model || $model->status !== 'pending') {
                return;
            }
            // Still pending ~ after queue push — leave to worker; do not double-run.
        })->afterResponse();
    }

    protected function ensureJobBelongsToWorkspace(MapsScrapeJob $job): void
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        abort_unless((int) $job->workspace_id === (int) $workspace->id, 404);
    }

    /**
     * @return list<string>
     */
    protected function usStates(): array
    {
        $path = base_path('tools/google-maps-scraper/data/us_states.json');
        if (! is_file($path)) {
            return ['Alabama', 'California', 'Texas', 'Florida', 'New York', 'Georgia'];
        }

        $json = json_decode((string) file_get_contents($path), true);
        $states = $json['states'] ?? $json;
        if (! is_array($states)) {
            return ['Georgia'];
        }

        $names = [];
        foreach ($states as $item) {
            if (is_string($item)) {
                $names[] = $item;
            } elseif (is_array($item) && isset($item['name'])) {
                $names[] = (string) $item['name'];
            }
        }

        return $names !== [] ? $names : ['Georgia'];
    }
}
