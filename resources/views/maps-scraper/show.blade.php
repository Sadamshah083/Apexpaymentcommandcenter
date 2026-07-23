@extends('layouts.admin')

@section('title', 'Scrape job #'.$job->id)

@section('content')
    <div class="app-page" data-maps-job-status
        data-status-url="{{ route('admin.maps-scraper.status', $job) }}"
        data-complete="{{ $job->isComplete() ? '1' : '0' }}">
        <div class="app-page-header">
            <div>
                <h1 class="app-page-title">Maps scrape #{{ $job->id }}</h1>
                <p class="app-page-subtitle">
                    Mode: <strong>{{ $job->job_mode }}</strong>
                    @if ($job->job_mode === 'quick')
                        — {{ $job->search_query }}
                    @elseif ($job->job_mode === 'state')
                        — {{ $job->state }} · {{ $job->business }}
                    @else
                        — {{ $job->search_query ?: (($job->state ?: '').' · '.($job->business ?: '')) }}
                    @endif
                </p>
            </div>
            <div class="um-page-header-actions" style="display:flex;gap:0.5rem;flex-wrap:wrap">
                <a href="{{ route('admin.maps-scraper.index') }}" class="um-btn um-btn-soft um-btn-sm">Back</a>
                <button type="button" class="um-btn um-btn-soft um-btn-sm" data-clear-terminal>Clear terminal</button>
                @if ($job->status === 'completed' && $job->export_zip_path)
                    <a href="{{ route('admin.maps-scraper.download', $job) }}" class="um-btn um-btn-primary um-btn-sm" data-download-btn>
                        Download Excel ZIP
                    </a>
                @else
                    <a href="{{ route('admin.maps-scraper.download', $job) }}" class="um-btn um-btn-primary um-btn-sm" data-download-btn hidden>
                        Download Excel ZIP
                    </a>
                @endif
            </div>
        </div>

        @if (session('success'))
            <div class="app-card app-card-padded mb-4" style="border-color:#86efac;background:#f0fdf4;color:#166534">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4 app-stat-grid mb-4">
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Status</p>
                <p class="app-kpi-value" data-job-status style="font-size:1.25rem">{{ $job->status }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Progress</p>
                <p class="app-kpi-value" data-job-pct style="font-size:1.25rem">{{ $job->progress_pct }}%</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Leads collecting</p>
                <p class="app-kpi-value" data-job-rows style="font-size:1.25rem">{{ number_format($job->row_count) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Excel files</p>
                <p class="app-kpi-value" data-job-files style="font-size:1.25rem">{{ number_format($job->file_count) }}</p>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3 mb-4">
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">State</p>
                <p data-job-state>{{ $job->state ?: '—' }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">City</p>
                <p data-job-city>{{ $job->meta['city'] ?? '—' }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Category</p>
                <p data-job-category>{{ $job->business ?: ($job->meta['category'] ?? '—') }}</p>
            </div>
        </div>

        <section class="app-card app-card-padded mb-4">
            <h2 class="app-section-title">Google Maps scraper terminal</h2>
            <p class="mt-2 text-sm" style="color:#334155" data-job-message>{{ $job->progress_message ?: '—' }}</p>
            @if ($job->error_message)
                <p class="mt-2 text-sm" style="color:#991b1b" data-job-error>{{ $job->error_message }}</p>
            @else
                <p class="mt-2 text-sm" style="color:#991b1b;display:none" data-job-error></p>
            @endif

            <div class="maps-scraper-terminal mt-3" data-job-terminal>
                @foreach (($job->meta['live_logs'] ?? []) as $line)
                    <div class="maps-scraper-terminal__line">{{ $line }}</div>
                @endforeach
            </div>

            @php $groups = $job->meta['area_code_groups'] ?? []; @endphp
            @if (is_array($groups) && $groups !== [])
                <div class="agent-status-table-wrap" style="margin-top:1rem">
                    <table class="agent-status-table">
                        <thead>
                            <tr>
                                <th>Area code</th>
                                <th>State (from NPA)</th>
                                <th>Leads</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $npa => $count)
                                <tr>
                                    <td class="tabular-nums">{{ $npa }}</td>
                                    <td>{{ \App\Support\UsAreaCodeState::stateNameFromAreaCode((string) $npa) ?: '—' }}</td>
                                    <td class="tabular-nums">{{ number_format((int) $count) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <style>
        .maps-scraper-terminal {
            background: #0b1220;
            color: #bbf7d0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.78rem;
            line-height: 1.5;
            border-radius: 0.65rem;
            padding: 0.85rem 1rem;
            min-height: 14rem;
            max-height: 22rem;
            overflow: auto;
            border: 1px solid #1f2937;
        }
        .maps-scraper-terminal__line { white-space: pre-wrap; word-break: break-word; }
        .maps-scraper-terminal__line.is-error { color: #fca5a5; }
        .maps-scraper-terminal__line.is-ok { color: #86efac; }
    </style>

    <script>
        (function () {
            const root = document.querySelector('[data-maps-job-status]');
            if (!root) return;

            document.querySelector('[data-clear-terminal]')?.addEventListener('click', () => {
                const term = root.querySelector('[data-job-terminal]');
                if (term) term.innerHTML = '';
            });

            if (root.dataset.complete === '1') return;

            const url = root.dataset.statusUrl;
            const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));

            const tick = async () => {
                try {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) return;
                    const data = await res.json();
                    const set = (sel, val) => { const el = root.querySelector(sel); if (el) el.textContent = val; };
                    set('[data-job-status]', data.status || '—');
                    set('[data-job-pct]', (data.progress_pct ?? 0) + '%');
                    set('[data-job-rows]', Number(data.row_count || 0).toLocaleString());
                    set('[data-job-files]', Number(data.file_count || 0).toLocaleString());
                    set('[data-job-message]', data.progress_message || '—');
                    if (data.state) set('[data-job-state]', data.state);
                    if (data.city) set('[data-job-city]', data.city);
                    if (data.category) set('[data-job-category]', data.category);

                    const err = root.querySelector('[data-job-error]');
                    if (err) {
                        if (data.error_message) {
                            err.style.display = '';
                            err.textContent = data.error_message;
                        } else {
                            err.style.display = 'none';
                            err.textContent = '';
                        }
                    }

                    const term = root.querySelector('[data-job-terminal]');
                    if (term && Array.isArray(data.logs)) {
                        term.innerHTML = data.logs.map((line) => {
                            const lower = String(line).toLowerCase();
                            let cls = 'maps-scraper-terminal__line';
                            if (lower.includes('fail') || lower.includes('error')) cls += ' is-error';
                            else if (lower.includes('saved') || lower.includes('done') || lower.includes('found')) cls += ' is-ok';
                            return '<div class="' + cls + '">' + escapeHtml(line) + '</div>';
                        }).join('');
                        term.scrollTop = term.scrollHeight;
                    }

                    const dl = root.querySelector('[data-download-btn]');
                    if (dl && data.download_ready) {
                        dl.hidden = false;
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
