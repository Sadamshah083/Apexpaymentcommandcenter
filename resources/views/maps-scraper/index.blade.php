@extends('layouts.admin')

@section('title', 'Maps Lead Scraper')

@section('content')
    <div class="app-page maps-scraper-page">
        <div class="app-page-header">
            <div>
                <h1 class="app-page-title">Maps Lead Scraper</h1>
                <p class="app-page-subtitle">
                    Free Google Maps scrape (Playwright — no Places API billing). Small businesses only. Excel by area code.
                </p>
            </div>
        </div>

        @if (session('success'))
            <div class="app-card app-card-padded mb-4" style="border-color:#86efac;background:#f0fdf4;color:#166534">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="app-card app-card-padded mb-4" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b">
                {{ session('error') }}
            </div>
        @endif
        @error('scraper')
            <div class="app-card app-card-padded mb-4" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b">
                {{ $message }}
            </div>
        @enderror

        @if (! $ready)
            <div class="app-card app-card-padded mb-4" style="border-color:#fcd34d;background:#fffbeb;color:#92400e">
                Scraper tools are not ready: {{ $readyError }}
            </div>
        @endif

        @if ($stalePending)
            <div class="app-card app-card-padded mb-4" style="border-color:#fdba74;background:#fff7ed;color:#9a3412">
                A job has been pending for over 3 minutes. Check that PHP can run Python/Playwright, or open the job and retry.
            </div>
        @endif

        {{-- Live collection cards --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 mb-4" data-maps-stats>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Scraper status</p>
                <p class="app-kpi-value" style="font-size:1.15rem" data-stat-status>
                    {{ ($stats['running'] ?? 0) > 0 ? 'Collecting' : ($ready ? 'Ready' : 'Offline') }}
                </p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Active / pending</p>
                <p class="app-kpi-value" style="font-size:1.15rem" data-stat-running>{{ number_format($stats['running'] ?? 0) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Leads collected</p>
                <p class="app-kpi-value" style="font-size:1.15rem" data-stat-leads>{{ number_format($stats['leads_saved'] ?? 0) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Completed jobs</p>
                <p class="app-kpi-value" style="font-size:1.15rem">{{ number_format($stats['completed'] ?? 0) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Failed</p>
                <p class="app-kpi-value" style="font-size:1.15rem">{{ number_format($stats['failed'] ?? 0) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Excel files</p>
                <p class="app-kpi-value" style="font-size:1.15rem">{{ number_format($stats['files_ready'] ?? 0) }}</p>
            </div>
        </div>

        @if ($activeJob)
            <section class="app-card app-card-padded mb-4" data-maps-active
                data-status-url="{{ route('admin.maps-scraper.status', $activeJob) }}">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <h2 class="app-section-title" style="margin:0">Now collecting — Job #{{ $activeJob->id }}</h2>
                    <a href="{{ route('admin.maps-scraper.show', $activeJob) }}" class="um-btn um-btn-primary um-btn-sm">Open live terminal</a>
                </div>
                <div class="grid gap-3 sm:grid-cols-4 mb-3">
                    <div>
                        <p class="app-kpi-label">Status</p>
                        <p class="font-semibold" data-active-status>{{ $activeJob->status }}</p>
                    </div>
                    <div>
                        <p class="app-kpi-label">Progress</p>
                        <p class="font-semibold" data-active-pct>{{ $activeJob->progress_pct }}%</p>
                    </div>
                    <div>
                        <p class="app-kpi-label">Leads so far</p>
                        <p class="font-semibold" data-active-rows>{{ number_format($activeJob->row_count) }}</p>
                    </div>
                    <div>
                        <p class="app-kpi-label">Query</p>
                        <p class="text-sm" style="color:#475569" data-active-query>{{ \Illuminate\Support\Str::limit($activeJob->search_query ?: ($activeJob->state.' · '.$activeJob->business), 60) }}</p>
                    </div>
                </div>
                <p class="text-sm" style="color:#334155" data-active-message>{{ $activeJob->progress_message }}</p>
                <div class="maps-scraper-terminal mt-3" data-active-terminal aria-live="polite">
                    @foreach (($activeJob->meta['live_logs'] ?? []) as $line)
                        <div class="maps-scraper-terminal__line">{{ $line }}</div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Priority category Start table --}}
        <section class="app-card app-card-padded mb-4">
            <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
                <div>
                    <h2 class="app-section-title" style="margin:0">Start Google Maps search</h2>
                    <p class="app-page-subtitle" style="margin:0.35rem 0 0">
                        Select a <strong>state</strong>, then a <strong>city</strong>. The scraper collects <strong>all available</strong> business listings for that city (no early stop).
                    </p>
                </div>
                <form method="GET" class="flex flex-wrap gap-2 items-end" onsubmit="return false;">
                    <div>
                        <label class="ghl-dialer-leads-label" for="quick_state">State</label>
                        <select id="quick_state" class="um-input" style="min-width:10rem" data-maps-cities-url="{{ route('admin.maps-scraper.cities') }}">
                            @foreach ($states as $stateName)
                                <option value="{{ $stateName }}" @selected($stateName === $defaultState)>{{ $stateName }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ghl-dialer-leads-label" for="quick_city">City</label>
                        <select id="quick_city" class="um-input" style="min-width:11rem">
                            <option value="{{ $defaultCity }}" selected>{{ $defaultCity }}</option>
                        </select>
                    </div>
                </form>
            </div>

            <div class="agent-status-table-wrap">
                <table class="agent-status-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Business category</th>
                            <th>Search query preview</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $i => $category)
                            <tr>
                                <td class="tabular-nums">{{ $i + 1 }}</td>
                                <td>{{ $category }}</td>
                                <td class="text-sm" style="color:#64748b" data-cat-preview data-category="{{ $category }}">
                                    {{ $category }} in {{ $defaultCity }}, {{ $defaultState }}, USA
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('admin.maps-scraper.store') }}" class="inline" data-maps-start-form>
                                        @csrf
                                        <input type="hidden" name="job_mode" value="quick">
                                        <input type="hidden" name="category" value="{{ $category }}">
                                        <input type="hidden" name="state" value="{{ $defaultState }}" data-start-state>
                                        <input type="hidden" name="city" value="{{ $defaultCity }}" data-start-city>
                                        <input type="hidden" name="per_search" value="0" data-start-per>
                                        <button type="submit" class="um-btn um-btn-primary um-btn-sm" @disabled(! $ready)>
                                            Start
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2 mb-4">
            <section class="app-card app-card-padded">
                <h2 class="app-section-title">Custom scrape</h2>
                <form method="POST" action="{{ route('admin.maps-scraper.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3" data-maps-scraper-form>
                    @csrf

                    <div>
                        <label class="ghl-dialer-leads-label" for="job_mode">Mode</label>
                        <select name="job_mode" id="job_mode" class="um-input" required data-maps-mode>
                            <option value="quick" @selected(old('job_mode', 'quick') === 'quick')>Quick search</option>
                            <option value="state" @selected(old('job_mode') === 'state')>Full state scrape</option>
                            <option value="csv" @selected(old('job_mode') === 'csv')>CSV → area-code Excel</option>
                        </select>
                    </div>

                    <div data-maps-panel="quick">
                        <label class="ghl-dialer-leads-label" for="search_query">Google Maps search</label>
                        <input type="text" name="search_query" id="search_query" class="um-input"
                            value="{{ old('search_query', 'Auto Repair Shop in Atlanta, Georgia, USA') }}"
                            placeholder="e.g. Hair Salon in Dallas, Texas, USA">
                        @error('search_query')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div data-maps-panel="state" hidden>
                        <label class="ghl-dialer-leads-label" for="state">State</label>
                        <select name="state" id="state" class="um-input">
                            @foreach ($states as $stateName)
                                <option value="{{ $stateName }}" @selected(old('state', $defaultState) === $stateName)>{{ $stateName }}</option>
                            @endforeach
                        </select>

                        <label class="ghl-dialer-leads-label" for="business" style="margin-top:0.75rem;display:block">Business type(s)</label>
                        <input type="text" name="business" id="business" class="um-input"
                            value="{{ old('business', 'Auto Repair Shop,Tire Shop') }}"
                            placeholder="Comma-separated categories">
                        @error('business')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror

                        <label class="ghl-dialer-leads-label" for="scrape_mode" style="margin-top:0.75rem;display:block">Coverage</label>
                        <select name="scrape_mode" id="scrape_mode" class="um-input">
                            <option value="city" @selected(old('scrape_mode', 'city') === 'city')>Cities only (faster)</option>
                            <option value="both" @selected(old('scrape_mode') === 'both')>Cities + grid (max coverage)</option>
                            <option value="grid" @selected(old('scrape_mode') === 'grid')>Grid only</option>
                        </select>
                    </div>

                    <div data-maps-panel="csv" hidden>
                        <label class="ghl-dialer-leads-label" for="csv_file">Existing scraper CSV</label>
                        <input type="file" name="csv_file" id="csv_file" class="um-input" accept=".csv,text/csv">
                        @error('csv_file')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div data-maps-panel="limits" hidden>
                        <input type="hidden" name="per_search" id="per_search" value="0">
                        <p class="text-sm" style="color:#64748b;margin:0">Collects every listing Google Maps returns for the selected search (no max cap).</p>
                    </div>

                    <button type="submit" class="um-btn um-btn-primary" @disabled(! $ready)>
                        Start Google Maps Search
                    </button>
                </form>
            </section>

            <section class="app-card app-card-padded">
                <h2 class="app-section-title">How it works</h2>
                <ul class="mt-3 space-y-2 text-sm" style="color:#334155;line-height:1.5">
                    <li><strong>Free:</strong> uses Google Maps in a headless browser — no Google Places API key or billing.</li>
                    <li>Click <strong>Start</strong> on any category row to queue a search and open the live job page.</li>
                    <li>Leads update on the cards and in the terminal while the scrape runs.</li>
                    <li>Chains / corporate offices are filtered out (small businesses only).</li>
                    <li>Download a ZIP of Excel files grouped by phone area code.</li>
                </ul>
            </section>
        </div>

        <section class="app-card app-card-padded">
            <h2 class="app-section-title">Recent jobs</h2>
            <div class="agent-status-table-wrap" style="margin-top:0.75rem">
                <table class="agent-status-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Mode</th>
                            <th>Query / State</th>
                            <th>Status</th>
                            <th>Leads</th>
                            <th>Files</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($jobs as $job)
                            <tr>
                                <td class="whitespace-nowrap">{{ $job->created_at?->timezone(config('app.timezone'))->format('M j, g:i A') }}</td>
                                <td>{{ $job->job_mode }}</td>
                                <td style="max-width:18rem;white-space:normal">
                                    {{ $job->search_query ?: (($job->state ?: '—').' · '.($job->business ?: '—')) }}
                                </td>
                                <td>
                                    <span class="maps-job-status maps-job-status--{{ $job->status }}">{{ $job->status }}</span>
                                </td>
                                <td class="tabular-nums">{{ number_format($job->row_count) }}</td>
                                <td class="tabular-nums">{{ number_format($job->file_count) }}</td>
                                <td>
                                    <a class="um-btn um-btn-soft um-btn-sm" href="{{ route('admin.maps-scraper.show', $job) }}">Open</a>
                                    @if ($job->status === 'completed' && $job->export_zip_path)
                                        <a class="um-btn um-btn-primary um-btn-sm" href="{{ route('admin.maps-scraper.download', $job) }}">ZIP</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="agent-status-empty">No scrape jobs yet — use Start on a category above.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $jobs->links() }}</div>
        </section>
    </div>

    <style>
        .maps-scraper-terminal {
            background: #0b1220;
            color: #bbf7d0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.75rem;
            line-height: 1.45;
            border-radius: 0.65rem;
            padding: 0.75rem 0.9rem;
            max-height: 12rem;
            overflow: auto;
            border: 1px solid #1f2937;
        }
        .maps-scraper-terminal__line { white-space: pre-wrap; word-break: break-word; }
        .maps-job-status { text-transform: capitalize; font-weight: 600; }
        .maps-job-status--running, .maps-job-status--pending { color: #059669; }
        .maps-job-status--completed { color: #2563eb; }
        .maps-job-status--failed { color: #dc2626; }
    </style>

    <script>
        (function () {
            const select = document.querySelector('[data-maps-mode]');
            if (select) {
                const sync = () => {
                    const mode = select.value;
                    document.querySelectorAll('[data-maps-panel]').forEach((el) => {
                        const key = el.getAttribute('data-maps-panel');
                        if (key === 'limits') {
                            el.hidden = mode === 'csv';
                            return;
                        }
                        el.hidden = key !== mode;
                    });
                };
                select.addEventListener('change', sync);
                sync();
            }

            const stateEl = document.getElementById('quick_state');
            const cityEl = document.getElementById('quick_city');
            const citiesUrl = stateEl?.dataset.mapsCitiesUrl || '';
            const defaultCity = @json($defaultCity);

            const refreshPreviews = () => {
                const state = stateEl?.value || 'Georgia';
                const city = cityEl?.value || defaultCity || 'Atlanta';
                document.querySelectorAll('[data-start-state]').forEach((el) => { el.value = state; });
                document.querySelectorAll('[data-start-city]').forEach((el) => { el.value = city; });
                document.querySelectorAll('[data-start-per]').forEach((el) => { el.value = '0'; });
                document.querySelectorAll('[data-cat-preview]').forEach((el) => {
                    const cat = el.getAttribute('data-category') || '';
                    el.textContent = cat + ' in ' + city + ', ' + state + ', USA';
                });
            };

            const loadCities = async (preferredCity) => {
                if (!stateEl || !cityEl || !citiesUrl) {
                    refreshPreviews();
                    return;
                }
                const state = stateEl.value;
                cityEl.disabled = true;
                cityEl.innerHTML = '<option value="">Loading cities…</option>';
                try {
                    const res = await fetch(citiesUrl + '?state=' + encodeURIComponent(state), {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await res.json().catch(() => ({ cities: [] }));
                    const cities = Array.isArray(data.cities) ? data.cities : [];
                    if (cities.length === 0) {
                        cityEl.innerHTML = '<option value="' + (preferredCity || defaultCity) + '">' + (preferredCity || defaultCity) + '</option>';
                    } else {
                        const pick = preferredCity && cities.includes(preferredCity)
                            ? preferredCity
                            : (cities.includes(defaultCity) ? defaultCity : cities[0]);
                        cityEl.innerHTML = cities.map((name) => {
                            const selected = name === pick ? ' selected' : '';
                            return '<option value="' + String(name).replace(/"/g, '&quot;') + '"' + selected + '>' + name + '</option>';
                        }).join('');
                    }
                } catch (e) {
                    cityEl.innerHTML = '<option value="' + (preferredCity || defaultCity) + '">' + (preferredCity || defaultCity) + '</option>';
                } finally {
                    cityEl.disabled = false;
                    refreshPreviews();
                }
            };

            stateEl?.addEventListener('change', () => loadCities(null));
            cityEl?.addEventListener('change', refreshPreviews);
            loadCities(defaultCity);

            document.querySelectorAll('[data-maps-start-form]').forEach((form) => {
                form.addEventListener('submit', () => {
                    const btn = form.querySelector('button[type="submit"]');
                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = 'Starting…';
                    }
                });
            });

            const active = document.querySelector('[data-maps-active]');
            if (!active) return;
            const url = active.dataset.statusUrl;
            const tick = async () => {
                try {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) return;
                    const data = await res.json();
                    const set = (sel, val) => { const el = active.querySelector(sel); if (el) el.textContent = val; };
                    set('[data-active-status]', data.status || '—');
                    set('[data-active-pct]', (data.progress_pct ?? 0) + '%');
                    set('[data-active-rows]', Number(data.row_count || 0).toLocaleString());
                    set('[data-active-message]', data.progress_message || '—');
                    if (data.query) set('[data-active-query]', data.query);
                    const term = active.querySelector('[data-active-terminal]');
                    if (term && Array.isArray(data.logs)) {
                        term.innerHTML = data.logs.map((line) => {
                            const safe = String(line).replace(/[&<>"']/g, (c) => ({
                                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                            }[c]));
                            return '<div class="maps-scraper-terminal__line">' + safe + '</div>';
                        }).join('');
                        term.scrollTop = term.scrollHeight;
                    }
                    if (data.complete) {
                        window.location.reload();
                    }
                } catch (e) {}
            };
            setInterval(tick, 8000);
            tick();
        })();
    </script>
@endsection
