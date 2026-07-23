@php
    $routePrefix = $routePrefix ?? 'admin.';
    $recordingSyncUrl = route($routePrefix.'communications.dialer.recording.sync');
    $summaryUrl = $summaryUrl ?? route($routePrefix.'communications.agent-status.summary');
    $exportSummariesUrl = $exportSummariesUrl ?? route($routePrefix.'communications.agent-status.export-summaries', request()->query());
    $viewerTier = $viewerTier ?? 'admin';
    $roleEyebrow = match ($viewerTier) {
        'agent' => 'Agent',
        'team_lead' => 'Team Lead',
        'qa' => 'QA',
        'supervisor' => 'Supervisor',
        default => 'Admin',
    };
@endphp

<div
    class="app-page agent-status-page"
    data-all-call-logs
    data-recording-sync-url="{{ $recordingSyncUrl }}"
    data-ai-summary-url="{{ $summaryUrl }}"
>
    <div class="agent-status-main">
        <div class="app-page-header agent-status-header">
            <div>
                <p class="agent-status-page-eyebrow">{{ $roleEyebrow }}</p>
                <h1 class="app-page-title">All call logs</h1>
            </div>
        </div>

        <div class="agent-status-toolbar app-card">
            <div class="agent-status-toolbar__intro">
                <p class="agent-status-toolbar__eyebrow">{{ $roleEyebrow }}</p>
                <p class="agent-status-toolbar__title">All call logs</p>
            </div>
            <nav class="agent-status-toolbar__nav" aria-label="Call logs pages">
                <a href="{{ $monitoringUrl }}" class="agent-status-toolbar__link">Live Monitoring</a>
                <a href="{{ $formUrl }}" class="agent-status-toolbar__link is-active" aria-current="page">All call logs</a>
            </nav>
            <div class="agent-status-toolbar__stats">
                <div class="agent-status-toolbar__stat">
                    <span class="agent-status-toolbar__stat-label">Calls</span>
                    <span class="agent-status-toolbar__stat-value">{{ number_format($totalCalls) }}</span>
                </div>
                <div class="agent-status-toolbar__stat">
                    <span class="agent-status-toolbar__stat-label">Talk</span>
                    <span class="agent-status-toolbar__stat-value">{{ $totalDurationLabel }}</span>
                </div>
            </div>
            <div class="agent-status-toolbar__actions">
                <a href="{{ $exportUrl }}" class="agent-status-outline-btn is-green">Export status</a>
                <a href="{{ $exportLogsUrl }}" class="agent-status-outline-btn is-blue">Export call logs</a>
                <a href="{{ $exportSummariesUrl }}" class="agent-status-outline-btn is-green">Download all summary</a>
            </div>
        </div>

        <form method="get" action="{{ $formUrl }}" class="agent-status-filters app-card app-card-padded">
            <div class="agent-status-filters__grid">
                <label class="agent-status-field">
                    <span class="agent-status-field__label">From</span>
                    <input type="date" name="from" value="{{ $from }}" class="app-input js-pretty-date" data-pretty-date required>
                </label>
                <label class="agent-status-field">
                    <span class="agent-status-field__label">To</span>
                    <input type="date" name="to" value="{{ $to }}" class="app-input js-pretty-date" data-pretty-date required>
                </label>
                <label class="agent-status-field{{ !empty($agentOnlyView) ? ' is-locked' : '' }}">
                    <span class="agent-status-field__label">User</span>
                    @if (!empty($agentOnlyView))
                        <input type="hidden" name="user_id" value="{{ (int) $selectedAgentId }}">
                        <div class="agent-status-user-locked app-input">{{ $agents->first()['name'] ?? 'My calls' }}</div>
                    @else
                        <select name="user_id" class="app-input js-pretty-select" data-pretty-select>
                            <option value="0">All users</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent['id'] }}" @selected((int) $selectedAgentId === (int) $agent['id'])>
                                    {{ $agent['name'] }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </label>
                <label class="agent-status-field">
                    <span class="agent-status-field__label">Disposition</span>
                    <select
                        name="disposition_choice"
                        class="app-input js-pretty-select"
                        data-pretty-select
                        data-disposition-filter
                        data-disposition-choice
                    >
                        <option value="">All dispositions</option>
                        @foreach (($dispositionOptions ?? []) as $dispositionLabel)
                            <option value="{{ $dispositionLabel }}" @selected(($dispositionChoiceValue ?? '') === $dispositionLabel)>
                                {{ $dispositionLabel }}
                            </option>
                        @endforeach
                        <option value="__other__" @selected(($dispositionChoiceValue ?? '') === '__other__')>Other</option>
                    </select>
                </label>
                <label class="agent-status-field{{ empty($showDispositionOther) ? ' hidden' : '' }}" data-disposition-other-wrap>
                    <span class="agent-status-field__label">Custom disposition</span>
                    <input
                        type="text"
                        name="disposition_other"
                        value="{{ !empty($showDispositionOther) ? ($selectedDisposition ?? '') : '' }}"
                        class="app-input"
                        data-disposition-other-input
                        maxlength="120"
                        placeholder="Enter disposition"
                        autocomplete="off"
                        @if (!empty($showDispositionOther)) required @endif
                    >
                </label>
                <input type="hidden" name="disposition" value="{{ $selectedDisposition ?? '' }}" data-disposition-hidden>
                @if (filled($phoneSearch ?? null))
                    <input type="hidden" name="phone" value="{{ $phoneSearch }}">
                @endif
                <div class="agent-status-filters__submit">
                    <span class="agent-status-field__label agent-status-field__label--spacer" aria-hidden="true">&nbsp;</span>
                    <button type="submit" class="agent-status-apply-btn">Apply range</button>
                </div>
            </div>
        </form>

        <section class="app-card app-card-padded agent-status-disposition-summary" aria-label="Disposition summary">
            <div class="agent-status-section-head">
                <div>
                    <h2 class="app-section-title">Disposition summary</h2>
                    <p class="agent-status-section-sub">Totals submitted by agents for the selected range.</p>
                </div>
                <div class="agent-status-disposition-summary__totals">
                    <div class="agent-status-disposition-summary__total">
                        <strong>{{ number_format($totalCalls) }}</strong>
                        <span>calls</span>
                    </div>
                    <div class="agent-status-disposition-summary__total is-talk">
                        <strong>{{ $totalDurationLabel }}</strong>
                        <span>talk time</span>
                    </div>
                </div>
            </div>
            <div class="agent-status-disposition-cards">
                @forelse ($statusRows as $row)
                    @php
                        $cardLabel = trim((string) ($row['status'] ?? ''));
                        $cardCount = (int) ($row['count'] ?? 0);
                        $tone = \App\Support\DispositionTone::for($cardLabel);
                        $isActive = filled($selectedDisposition ?? null)
                            && mb_strtolower((string) $selectedDisposition) === mb_strtolower($cardLabel);
                        $cardQuery = array_filter([
                            'from' => $from,
                            'to' => $to,
                            'user_id' => (!empty($agentOnlyView) || (int) $selectedAgentId > 0) ? (int) $selectedAgentId : null,
                            'disposition_choice' => $cardLabel,
                            'disposition' => $cardLabel,
                            'phone' => filled($phoneSearch ?? null) ? $phoneSearch : null,
                        ], static fn ($value) => $value !== null && $value !== '');
                    @endphp
                    <a
                        href="{{ route($routePrefix.'communications.agent-status', $cardQuery) }}"
                        class="agent-status-disposition-card agent-status-disposition-card--{{ $tone }}{{ $isActive ? ' is-active' : '' }}"
                        title="Filter by {{ $cardLabel }}"
                    >
                        <span class="agent-status-disposition-card__name">{{ $cardLabel !== '' ? $cardLabel : 'Unknown' }}</span>
                        <strong class="agent-status-disposition-card__count">{{ number_format($cardCount) }}</strong>
                        <span class="agent-status-disposition-card__meta">{{ $row['duration_label'] ?? '0:00:00' }}</span>
                    </a>
                @empty
                    <p class="agent-status-empty" style="margin:0">No dispositions in this date range.</p>
                @endforelse
            </div>
        </section>

        <div class="agent-status-panels agent-status-panels--logs-only">
            <section class="app-card app-card-padded agent-status-logs">
                <div class="agent-status-section-head">
                    <div>
                        <h2 class="app-section-title">Call results &amp; recordings</h2>
                        <p class="agent-status-section-sub">Play recordings or open an AI call recording summary.</p>
                    </div>
                    <div class="agent-status-section-head__actions">
                        <form method="get" action="{{ $formUrl }}" class="agent-status-phone-search" role="search">
                            <input type="hidden" name="from" value="{{ $from }}">
                            <input type="hidden" name="to" value="{{ $to }}">
                            @if (!empty($agentOnlyView))
                                <input type="hidden" name="user_id" value="{{ (int) $selectedAgentId }}">
                            @elseif ((int) $selectedAgentId > 0)
                                <input type="hidden" name="user_id" value="{{ (int) $selectedAgentId }}">
                            @endif
                            @if (filled($selectedDisposition ?? null))
                                @if (!empty($showDispositionOther))
                                    <input type="hidden" name="disposition_choice" value="__other__">
                                    <input type="hidden" name="disposition_other" value="{{ $selectedDisposition }}">
                                @else
                                    <input type="hidden" name="disposition_choice" value="{{ $selectedDisposition }}">
                                @endif
                                <input type="hidden" name="disposition" value="{{ $selectedDisposition }}">
                            @endif
                            <label class="agent-status-phone-search__field">
                                <span class="sr-only">Search by number</span>
                                <svg class="agent-status-phone-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
                                </svg>
                                <input
                                    type="search"
                                    name="phone"
                                    value="{{ $phoneSearch ?? '' }}"
                                    class="agent-status-phone-search__input"
                                    placeholder="Search by number"
                                    inputmode="tel"
                                    autocomplete="off"
                                >
                            </label>
                            <button type="submit" class="agent-status-phone-search__btn">Search</button>
                            @if (filled($phoneSearch ?? null))
                                <a href="{{ route($routePrefix.'communications.agent-status', array_filter([
                                    'from' => $from,
                                    'to' => $to,
                                    'user_id' => (!empty($agentOnlyView) || (int) $selectedAgentId > 0) ? (int) $selectedAgentId : null,
                                    'disposition' => filled($selectedDisposition ?? null) ? $selectedDisposition : null,
                                    'disposition_choice' => !empty($showDispositionOther) ? '__other__' : (filled($selectedDisposition ?? null) ? $selectedDisposition : null),
                                    'disposition_other' => !empty($showDispositionOther) ? $selectedDisposition : null,
                                ])) }}" class="agent-status-phone-search__clear" title="Clear number search">Clear</a>
                            @endif
                        </form>
                        <button type="button" class="agent-status-outline-btn is-green" data-talk-status-open>
                            Talk time &amp; status
                        </button>
                        <span class="agent-status-muted">
                            @if ($callLogs instanceof \Illuminate\Contracts\Pagination\Paginator && $callLogs->total() > 0)
                                {{ number_format($callLogs->firstItem() ?? 0) }}–{{ number_format($callLogs->lastItem() ?? 0) }}
                                of {{ number_format($callLogs->total()) }}
                            @else
                                {{ number_format(is_countable($callLogs) ? count($callLogs) : 0) }} shown
                            @endif
                        </span>
                    </div>
                </div>
                <div class="agent-status-player hidden" data-all-call-logs-player>
                    <div class="agent-status-player__shell">
                        <audio class="agent-status-audio" data-all-call-logs-audio controls preload="auto" playsinline></audio>
                        <div class="agent-status-player__loading" data-all-call-logs-loading hidden aria-label="Loading recording" title="Loading recording">
                            <span class="agent-status-player__spinner" aria-hidden="true"></span>
                        </div>
                    </div>
                    <button type="button" class="agent-status-player__close" data-all-call-logs-close title="Close recording" aria-label="Close recording">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        <span>Close</span>
                    </button>
                </div>
                <div class="agent-status-table-wrap agent-status-table-wrap--scroll agent-status-table-wrap--viewport">
                    <table class="agent-status-table agent-status-table--logs">
                        <thead>
                            <tr>
                                <th scope="col">Agent</th>
                                <th scope="col">When</th>
                                <th scope="col">Status</th>
                                <th scope="col">Duration</th>
                                <th scope="col">Phone</th>
                                <th scope="col">Recording</th>
                                <th scope="col">Summary</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($callLogs as $log)
                                @php
                                    $hasRec = !empty($log['has_recording']) && !empty($log['play_url']);
                                    $playUrl = (string) ($log['play_url'] ?? '');
                                    $downloadUrl = (string) ($log['download_url'] ?? '');
                                    if ($hasRec && $downloadUrl === '' && $playUrl !== '') {
                                        $downloadUrl = str_contains($playUrl, 'action=play')
                                            ? str_replace('action=play', 'action=download', $playUrl)
                                            : $playUrl.(str_contains($playUrl, '?') ? '&' : '?').'action=download';
                                    }
                                @endphp
                                <tr
                                    data-call-log-row
                                    data-call-log-ref="{{ $log['call_log_ref'] ?? '' }}"
                                    data-call-uuid="{{ $log['call_uuid'] ?? '' }}"
                                    data-play-url="{{ $playUrl }}"
                                    data-download-url="{{ $downloadUrl }}"
                                    data-has-recording="{{ $hasRec ? '1' : '0' }}"
                                    data-agent="{{ e($log['agent'] ?? '') }}"
                                    data-status="{{ e($log['status'] ?? '') }}"
                                    data-from-phone="{{ e($log['from_phone'] ?? '') }}"
                                    data-to-phone="{{ e($log['to_phone'] ?? $log['phone'] ?? '') }}"
                                    data-duration-label="{{ e($log['duration_label'] ?? '') }}"
                                    data-duration-sec="{{ (int) ($log['duration_sec'] ?? 0) }}"
                                    data-when="{{ e($log['when'] ?? '') }}"
                                    data-summary-cache="{{ e($log['ai_summary'] ?? '') }}"
                                >
                                    <td class="agent-status-agent">{{ $log['agent'] }}</td>
                                    <td class="agent-status-when whitespace-nowrap">{{ $log['when'] }}</td>
                                    <td>
                                        @php
                                            $statusLabel = trim((string) ($log['status'] ?? ''));
                                            $statusTone = \App\Support\DispositionTone::for($statusLabel);
                                        @endphp
                                        @if ($statusLabel === '' || $statusLabel === '—')
                                            <span class="agent-status-muted">—</span>
                                        @else
                                            <span class="agent-status-pill agent-status-pill--{{ $statusTone }}" title="{{ $statusLabel }}">{{ $statusLabel }}</span>
                                        @endif
                                    </td>
                                    <td class="agent-status-duration tabular-nums">{{ $log['duration_label'] }}</td>
                                    <td class="agent-status-phone tabular-nums">{{ $log['phone'] }}</td>
                                    <td>
                                        <div class="agent-status-rec-actions" data-recording-cell>
                                            @if ($hasRec)
                                                <button type="button" class="agent-status-rec-btn is-play is-icon" data-recording-play title="Play recording" aria-label="Play recording">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 001.5.86l11-6.86a1 1 0 000-1.72l-11-6.86A1 1 0 008 5.14z"/></svg>
                                                </button>
                                                <button type="button" class="agent-status-rec-btn is-download is-icon" data-recording-download title="Download recording" aria-label="Download recording">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                                                </button>
                                            @elseif (($log['duration_sec'] ?? 0) > 0 || filled($log['call_uuid'] ?? null))
                                                <button type="button" class="agent-status-rec-btn is-sync is-icon" data-recording-sync title="Find recording" aria-label="Find recording">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-3-6.7M21 3v6h-6"/></svg>
                                                </button>
                                                <span class="agent-status-muted-rec" data-recording-label>{{ ($log['recording_status'] ?? '') === 'pending' ? 'Pending' : '—' }}</span>
                                            @else
                                                <span class="agent-status-muted-rec">—</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="agent-status-summary-btn{{ !empty($log['has_ai_summary']) ? ' is-ready' : '' }}"
                                            data-ai-summary-btn
                                            title="AI call recording summary"
                                        >
                                            Summary
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="agent-status-empty">
                                        @if (filled($phoneSearch ?? null) || filled($selectedDisposition ?? null))
                                            No call logs match{{ filled($selectedDisposition ?? null) ? ' that disposition' : '' }}{{ filled($phoneSearch ?? null) ? (filled($selectedDisposition ?? null) ? ' and number' : ' that number') : '' }} in this date range.
                                        @else
                                            No call logs in this date range.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($callLogs instanceof \Illuminate\Contracts\Pagination\Paginator && $callLogs->total() > 0)
                    <x-pagination :paginator="$callLogs" class="mt-4" />
                @endif
            </section>
        </div>
    </div>

    <div class="ai-call-summary-overlay hidden" data-talk-status-modal aria-hidden="true">
        <div class="ai-call-summary-dialog talk-status-dialog" role="dialog" aria-modal="true" aria-labelledby="talk-status-title">
            <header class="ai-call-summary-header">
                <div>
                    <h2 id="talk-status-title" class="ai-call-summary-title">Talk time &amp; status</h2>
                    <p class="ai-call-summary-meta">Disposition counts and talk time for the selected range.</p>
                </div>
                <button type="button" class="ai-call-summary-close" data-talk-status-close title="Close" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </header>
            <div class="ai-call-summary-body talk-status-body">
                <div class="talk-status-toolbar">
                    <a href="{{ $exportUrl }}" class="agent-status-outline-btn is-green">Download CSV</a>
                </div>
                <div class="agent-status-table-wrap talk-status-table-wrap">
                    <table class="agent-status-table">
                        <thead>
                            <tr>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-right">Count</th>
                                <th scope="col" class="text-right">Hours:MM:SS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($statusRows as $row)
                                <tr>
                                    <td>
                                        @php $tone = \App\Support\DispositionTone::for($row['status'] ?? ''); @endphp
                                        <span class="agent-status-pill agent-status-pill--{{ $tone }}" title="{{ $row['status'] }}">{{ $row['status'] }}</span>
                                    </td>
                                    <td class="text-right">{{ number_format($row['count']) }}</td>
                                    <td class="text-right tabular-nums">{{ $row['duration_label'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="agent-status-empty">No dispositions in this date range.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($statusRows !== [])
                            <tfoot>
                                <tr>
                                    <th scope="row">TOTAL CALLS</th>
                                    <td class="text-right">{{ number_format($totalCalls) }}</td>
                                    <td class="text-right tabular-nums">{{ $totalDurationLabel }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="ai-call-summary-overlay hidden" data-ai-summary-modal aria-hidden="true">
        <div class="ai-call-summary-dialog" role="dialog" aria-modal="true" aria-labelledby="ai-call-summary-title">
            <header class="ai-call-summary-header">
                <div>
                    <h2 id="ai-call-summary-title" class="ai-call-summary-title">AI call summary</h2>
                    <p class="ai-call-summary-meta" data-ai-summary-meta></p>
                </div>
                <button type="button" class="ai-call-summary-close" data-ai-summary-close title="Close" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </header>
            <div class="ai-call-summary-body">
                <div class="ai-call-summary-loading" data-ai-summary-loading hidden>
                    <span class="agent-status-player__spinner" aria-hidden="true"></span>
                    <span>Generating AI call recording summary…</span>
                </div>
                <p class="ai-call-summary-text" data-ai-summary-body></p>
                <p class="ai-call-summary-error" data-ai-summary-error hidden></p>
            </div>
            <footer class="ai-call-summary-footer">
                <button type="button" class="ai-call-summary-download" data-ai-summary-download disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                    <span>Download summary</span>
                </button>
                <button type="button" class="ai-call-summary-regen" data-ai-summary-regen disabled>Regenerate</button>
            </footer>
        </div>
    </div>
</div>

@push('scripts')
<script>
(() => {
    function initAllCallLogsSummary() {
        const root = document.querySelector('[data-all-call-logs]');
        if (!root || root.dataset.summaryBound === '1') return;
        root.dataset.summaryBound = '1';

        const player = root.querySelector('[data-all-call-logs-player]');
        const audio = root.querySelector('[data-all-call-logs-audio]');
        const closeBtn = root.querySelector('[data-all-call-logs-close]');
        const loadingEl = root.querySelector('[data-all-call-logs-loading]');
        const syncUrl = root.dataset.recordingSyncUrl || '';
        const summaryUrl = root.dataset.aiSummaryUrl || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || '';

        let summaryModal = root.querySelector('[data-ai-summary-modal]');
        if (summaryModal && summaryModal.parentElement !== document.body) {
            document.body.appendChild(summaryModal);
        }
        let talkModal = root.querySelector('[data-talk-status-modal]');
        if (talkModal && talkModal.parentElement !== document.body) {
            document.body.appendChild(talkModal);
        }
        const summaryMeta = summaryModal?.querySelector('[data-ai-summary-meta]');
        const summaryText = summaryModal?.querySelector('[data-ai-summary-body]');
        const summaryError = summaryModal?.querySelector('[data-ai-summary-error]');
        const summaryLoading = summaryModal?.querySelector('[data-ai-summary-loading]');
        const summaryDownload = summaryModal?.querySelector('[data-ai-summary-download]');
        const summaryRegen = summaryModal?.querySelector('[data-ai-summary-regen]');
        const summaryClose = summaryModal?.querySelector('[data-ai-summary-close]');
        const talkOpenBtn = root.querySelector('[data-talk-status-open]');
        const talkCloseBtn = talkModal?.querySelector('[data-talk-status-close]');

        const filterForm = root.querySelector('.agent-status-filters');
        const dispositionChoice = filterForm?.querySelector('[data-disposition-choice]');
        const dispositionOtherWrap = filterForm?.querySelector('[data-disposition-other-wrap]');
        const dispositionOtherInput = filterForm?.querySelector('[data-disposition-other-input]');
        const dispositionHidden = filterForm?.querySelector('[data-disposition-hidden]');

        const syncDispositionFields = () => {
            if (!dispositionChoice || !dispositionHidden) return;
            const choice = dispositionChoice.value || '';
            const isOther = choice === '__other__';
            if (dispositionOtherWrap) {
                dispositionOtherWrap.classList.toggle('hidden', !isOther);
            }
            if (dispositionOtherInput) {
                dispositionOtherInput.required = isOther;
                if (!isOther) {
                    dispositionOtherInput.value = '';
                }
            }
            if (isOther) {
                dispositionHidden.value = (dispositionOtherInput?.value || '').trim();
            } else {
                dispositionHidden.value = choice;
            }
        };

        dispositionChoice?.addEventListener('change', () => {
            syncDispositionFields();
            if (dispositionChoice.value !== '__other__') {
                filterForm?.requestSubmit();
            } else {
                dispositionOtherInput?.focus();
            }
        });
        dispositionOtherInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                syncDispositionFields();
                filterForm?.requestSubmit();
            }
        });
        filterForm?.addEventListener('submit', () => {
            syncDispositionFields();
        });
        syncDispositionFields();

        const playIcon = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 001.5.86l11-6.86a1 1 0 000-1.72l-11-6.86A1 1 0 008 5.14z"/></svg>';
        const downloadIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>';
        const findIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-3-6.7M21 3v6h-6"/></svg>';
        const loadingIcon = '<span class="agent-status-rec-btn__spinner" aria-hidden="true"></span>';

        let activePlayBtn = null;
        let playToken = 0;
        /** @type {Map<string, { blobUrl: string, blob: Blob }>} */
        const urlCache = new Map();
        const MAX_CACHE_ENTRIES = 12;
        let activeSummaryRow = null;
        let summaryRequestToken = 0;
        let plainSummary = '';
        let downloadDoc = '';
        let summaryMetaPayload = {};

        function toDownloadUrl(url) {
            if (!url) return '';
            if (url.includes('action=download')) return url;
            if (url.includes('action=play')) return url.replace('action=play', 'action=download');
            return url + (url.includes('?') ? '&' : '?') + 'action=download';
        }

        function rememberCache(sourceUrl, blob, blobUrl) {
            if (urlCache.has(sourceUrl)) {
                const prev = urlCache.get(sourceUrl);
                if (prev?.blobUrl && prev.blobUrl !== blobUrl) {
                    try { URL.revokeObjectURL(prev.blobUrl); } catch (_) {}
                }
            }
            urlCache.set(sourceUrl, { blobUrl, blob });
            while (urlCache.size > MAX_CACHE_ENTRIES) {
                const oldest = urlCache.keys().next().value;
                if (!oldest) break;
                const entry = urlCache.get(oldest);
                urlCache.delete(oldest);
                if (entry?.blobUrl && audio?.src !== entry.blobUrl) {
                    try { URL.revokeObjectURL(entry.blobUrl); } catch (_) {}
                }
            }
        }

        function cachedBlobUrl(sourceUrl) {
            return urlCache.get(sourceUrl)?.blobUrl || '';
        }

        function setPlayerLoading(isLoading) {
            player?.classList.toggle('is-loading', Boolean(isLoading));
            if (loadingEl) loadingEl.hidden = !isLoading;
            if (activePlayBtn) {
                activePlayBtn.classList.toggle('is-loading', Boolean(isLoading));
                activePlayBtn.disabled = Boolean(isLoading);
                // Keep the play icon in place — spinner is CSS-only to avoid flicker.
            }
        }

        function closePlayer() {
            playToken += 1;
            if (!audio) return;
            try { audio.pause(); } catch (_) {}
            audio.removeAttribute('src');
            try { audio.load(); } catch (_) {}
            setPlayerLoading(false);
            activePlayBtn = null;
            player?.classList.add('hidden');
        }

        function seekToStart(el) {
            if (!el) return;
            try {
                el.currentTime = 0;
            } catch (_) {
                // Metadata may not be ready yet — retry on loadedmetadata.
            }
        }

        function waitForCanPlay(el, token) {
            return new Promise((resolve, reject) => {
                if (!el) { reject(new Error('No audio element')); return; }
                if (el.readyState >= 2) {
                    seekToStart(el);
                    resolve();
                    return;
                }
                const onReady = () => {
                    seekToStart(el);
                    cleanup();
                    resolve();
                };
                const onError = () => { cleanup(); reject(new Error('Recording failed to load')); };
                const cleanup = () => {
                    el.removeEventListener('canplay', onReady);
                    el.removeEventListener('canplaythrough', onReady);
                    el.removeEventListener('loadeddata', onReady);
                    el.removeEventListener('loadedmetadata', onMeta);
                    el.removeEventListener('error', onError);
                    clearTimeout(timer);
                };
                const onMeta = () => seekToStart(el);
                const timer = setTimeout(() => {
                    if (token !== playToken) { cleanup(); reject(new Error('cancelled')); return; }
                    seekToStart(el);
                    cleanup();
                    resolve();
                }, 8000);
                el.addEventListener('loadedmetadata', onMeta);
                el.addEventListener('canplay', onReady, { once: true });
                el.addEventListener('canplaythrough', onReady, { once: true });
                el.addEventListener('loadeddata', onReady, { once: true });
                el.addEventListener('error', onError, { once: true });
            });
        }

        async function playUrl(url, triggerBtn) {
            if (!audio || !url) return;
            const token = ++playToken;
            activePlayBtn = triggerBtn || activePlayBtn;
            player?.classList.remove('hidden');
            player?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            setPlayerLoading(true);
            try { audio.pause(); } catch (_) {}

            try {
                let playSrc = cachedBlobUrl(url);
                if (!playSrc) {
                    const res = await fetch(url, {
                        credentials: 'same-origin',
                        cache: 'force-cache',
                        headers: { Accept: 'audio/*,application/octet-stream,*/*' },
                    });
                    if (token !== playToken) return;
                    if (!res.ok) throw new Error('Recording failed to load');
                    const blob = await res.blob();
                    if (token !== playToken) return;
                    if (!blob.size) throw new Error('Recording is empty');
                    playSrc = URL.createObjectURL(blob);
                    rememberCache(url, blob, playSrc);
                }

                audio.preload = 'auto';
                // Always reload so playback starts at 0:00 on every Play click.
                if (audio.src !== playSrc) {
                    audio.src = playSrc;
                }
                audio.load();
                seekToStart(audio);
                await waitForCanPlay(audio, token);
                if (token !== playToken) return;
                seekToStart(audio);
                await audio.play();
                // Browsers sometimes ignore currentTime until play() begins — snap to start once.
                seekToStart(audio);
                if (token === playToken) setPlayerLoading(false);
            } catch (err) {
                if (token !== playToken) return;
                setPlayerLoading(false);
                if (String(err?.message || '') !== 'cancelled' && err?.name !== 'AbortError') {
                    window.showToast?.('Could not play recording. Try Download or Find again.', 'error');
                }
            }
        }

        async function downloadRecording(url, filenameHint, triggerBtn) {
            const downloadUrl = toDownloadUrl(url);
            if (!downloadUrl && !url) { window.showToast?.('Download link missing.', 'error'); return; }
            const btn = triggerBtn;
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
            }
            try {
                let blob = urlCache.get(url)?.blob || null;
                if (!blob) {
                    const fetchUrl = downloadUrl || url;
                    const res = await fetch(fetchUrl, {
                        credentials: 'same-origin',
                        cache: 'force-cache',
                        headers: { Accept: 'audio/*,application/octet-stream,*/*' },
                    });
                    if (!res.ok) throw new Error('Download failed');
                    blob = await res.blob();
                    if (url && blob?.size) {
                        rememberCache(url, blob, URL.createObjectURL(blob));
                    }
                }
                const objectUrl = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = objectUrl;
                a.download = filenameHint || 'call-recording.mp3';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(objectUrl), 2000);
                window.showToast?.('Recording download started.', 'success');
            } catch (_) {
                const a = document.createElement('a');
                a.href = downloadUrl || url;
                a.setAttribute('download', filenameHint || 'call-recording.mp3');
                a.rel = 'noopener';
                document.body.appendChild(a);
                a.click();
                a.remove();
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                }
            }
        }

        function renderReady(cell, playUrlValue, downloadUrl) {
            cell.innerHTML = '';
            const play = document.createElement('button');
            play.type = 'button';
            play.className = 'agent-status-rec-btn is-play is-icon';
            play.dataset.recordingPlay = '1';
            play.setAttribute('data-recording-play', '');
            play.title = 'Play recording';
            play.setAttribute('aria-label', 'Play recording');
            play.innerHTML = playIcon;
            cell.appendChild(play);
            const dl = document.createElement('button');
            dl.type = 'button';
            dl.className = 'agent-status-rec-btn is-download is-icon';
            dl.dataset.recordingDownload = '1';
            dl.setAttribute('data-recording-download', '');
            dl.title = 'Download recording';
            dl.setAttribute('aria-label', 'Download recording');
            dl.innerHTML = downloadIcon;
            cell.appendChild(dl);
            const row = cell.closest('[data-call-log-row]');
            if (row) {
                row.dataset.playUrl = playUrlValue || '';
                row.dataset.downloadUrl = toDownloadUrl(downloadUrl || playUrlValue || '');
                row.dataset.hasRecording = '1';
            }
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function highlightSummary(text) {
            return escapeHtml(text)
                .replace(/\*\*(.+?)\*\*/g, '<span class="ai-call-summary-em">$1</span>')
                .replace(/\n/g, '<br>');
        }

        function summaryStorageKey(ref) {
            return ref ? `acl_call_summary:${ref}` : '';
        }

        function readBrowserSummaryCache(ref) {
            const key = summaryStorageKey(ref);
            if (!key) return '';
            try {
                const raw = localStorage.getItem(key) || '';
                return String(raw).trim();
            } catch (_) {
                return '';
            }
        }

        function writeBrowserSummaryCache(ref, summary) {
            const key = summaryStorageKey(ref);
            const text = String(summary || '').trim();
            if (!key || !text) return;
            try {
                localStorage.setItem(key, text);
            } catch (_) {
                // ignore quota / private mode
            }
        }

        function buildCachedSummaryFromRow(row) {
            const ref = row.dataset.callLogRef || '';
            let cached = (row.dataset.summaryCache || '').trim();
            if (!cached) {
                cached = readBrowserSummaryCache(ref);
                if (cached) row.dataset.summaryCache = cached;
            }
            if (!cached) return null;
            const lower = cached.toLowerCase();
            const leakMarkers = [
                'write exactly',
                'hard rules',
                'double asterisks',
                'caller (agent):',
                'from number:',
                'facts for this call',
                'you write polished',
                'call recording context',
            ];
            if (leakMarkers.some((m) => lower.includes(m))) {
                row.dataset.summaryCache = '';
                try { localStorage.removeItem(summaryStorageKey(ref)); } catch (_) {}
                return null;
            }
            const agent = row.dataset.agent || '—';
            const toPhone = row.dataset.toPhone || '—';
            const fromPhone = row.dataset.fromPhone || '—';
            const status = row.dataset.status || 'Unknown';
            const duration = row.dataset.durationLabel || '0:00:00';
            const when = row.dataset.when || '—';
            return {
                summary: cached,
                summary_html: highlightSummary(cached),
                agent,
                from_phone: fromPhone,
                to_phone: toPhone,
                phone: toPhone,
                status,
                duration_label: duration,
                duration_sec: Number(row.dataset.durationSec || 0),
                when,
                cached: true,
                ai_enhanced: true,
            };
        }

        function buildLocalSummaryFromRow(row) {
            const agent = row.dataset.agent || 'the agent';
            const toPhone = row.dataset.toPhone || 'the contact';
            const status = row.dataset.status || 'Unknown';
            const duration = row.dataset.durationLabel || '0:00:00';
            const when = row.dataset.when || 'the selected time';
            const statusLower = status.toLowerCase();
            let mid = `The call lasted **${duration}**.`;
            if (statusLower.includes('no answer') || statusLower.includes('not available')) {
                mid = `The line rang for **${duration}** with **no meaningful conversation**.`;
            } else if (statusLower.includes('answering machine') || statusLower.includes('voicemail')) {
                mid = `The call reached an **answering machine / voicemail** after **${duration}**.`;
            } else if (statusLower.includes('hung up')) {
                mid = `The conversation lasted **${duration}** before the **contact hung up**.`;
            } else if (statusLower.includes('not interested')) {
                mid = `After **${duration}**, the contact was **not interested** in continuing.`;
            } else if (statusLower.includes('appointment') || statusLower.includes('call back') || statusLower.includes('callback')) {
                mid = `The call lasted **${duration}** and ended with a **follow-up arrangement**.`;
            }
            const summary = `The **agent**, **${agent}**, placed an **outbound** call to **${toPhone}** on **${when}**. ${mid} Disposition was logged as **${status}**.`;
            return {
                summary,
                summary_html: highlightSummary(summary),
                agent,
                from_phone: row.dataset.fromPhone || '—',
                to_phone: toPhone,
                phone: toPhone,
                status,
                duration_label: duration,
                duration_sec: Number(row.dataset.durationSec || 0),
                when,
                cached: false,
                ai_enhanced: false,
                pending_ai: true,
            };
        }

        function buildDownloadDoc(data) {
            if (data.download_text) return String(data.download_text);
            const summary = String(data.summary || '').replace(/\*\*/g, '');
            return [
                'AI call recording summary',
                '================================================',
                `Caller (agent): ${data.agent || '—'}`,
                `From number: ${data.from_phone || '—'}`,
                `Called number: ${data.to_phone || data.phone || '—'}`,
                `Disposition: ${data.status || '—'}`,
                `Duration: ${data.duration_label || '—'}`,
                `When: ${data.when || '—'}`,
                '',
                'AI summary',
                '------------------------------------------------',
                summary.trim(),
                '',
            ].join('\n');
        }

        function openOverlay(modal) {
            if (!modal) return;
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            modal.classList.remove('hidden');
            modal.classList.add('is-open');
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('ai-call-summary-open');
        }

        function closeOverlay(modal) {
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('is-open');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.ai-call-summary-overlay.is-open')) {
                document.body.classList.remove('ai-call-summary-open');
            }
        }

        function openSummaryModal() {
            openOverlay(summaryModal);
        }

        function closeSummaryModal() {
            closeOverlay(summaryModal);
            activeSummaryRow = null;
        }

        function openTalkModal() {
            openOverlay(talkModal);
        }

        function closeTalkModal() {
            closeOverlay(talkModal);
        }

        function setSummaryLoading(isLoading, message) {
            if (summaryLoading) {
                summaryLoading.hidden = !isLoading;
                const label = summaryLoading.querySelector('span:last-child');
                if (label && message) label.textContent = message;
            }
            if (summaryDownload) summaryDownload.disabled = !plainSummary;
            if (summaryRegen) summaryRegen.disabled = isLoading || !activeSummaryRow;
        }

        function clearSummaryBody() {
            plainSummary = '';
            downloadDoc = '';
            summaryMetaPayload = {};
            if (summaryText) {
                summaryText.innerHTML = '';
                summaryText.hidden = true;
            }
            if (summaryError) {
                summaryError.hidden = true;
                summaryError.textContent = '';
            }
            if (summaryDownload) summaryDownload.disabled = true;
        }

        function showSummaryResult(data) {
            plainSummary = String(data.summary || '').trim();
            summaryMetaPayload = data;
            downloadDoc = buildDownloadDoc(data);
            const phone = String(data.to_phone || data.phone || '').trim();
            const durLabel = String(data.duration_label || '').trim();
            if (summaryMeta) {
                summaryMeta.textContent = phone
                    ? `${phone}${durLabel ? ` (${durLabel})` : ''}`
                    : durLabel;
            }
            if (summaryError) {
                summaryError.hidden = true;
                summaryError.textContent = '';
            }
            if (summaryText) {
                summaryText.hidden = false;
                summaryText.innerHTML = data.summary_html || highlightSummary(plainSummary);
            }
            if (summaryDownload) summaryDownload.disabled = !plainSummary;
            if (summaryRegen) summaryRegen.disabled = !activeSummaryRow;
            if (activeSummaryRow && data.ai_enhanced !== false && !data.pending_ai) {
                const btn = activeSummaryRow.querySelector('[data-ai-summary-btn]');
                btn?.classList.add('is-ready');
                if (plainSummary) {
                    activeSummaryRow.dataset.summaryCache = plainSummary;
                    writeBrowserSummaryCache(activeSummaryRow.dataset.callLogRef || '', plainSummary);
                }
            }
        }

        async function fetchAiSummary(row, force = false) {
            if (!row || !summaryUrl) return;
            const requestToken = ++summaryRequestToken;
            activeSummaryRow = row;
            openSummaryModal();

            const cached = !force ? buildCachedSummaryFromRow(row) : null;
            if (cached) {
                showSummaryResult(cached);
                setSummaryLoading(false);
                return;
            }

            if (force) {
                row.dataset.summaryCache = '';
                try { localStorage.removeItem(summaryStorageKey(row.dataset.callLogRef || '')); } catch (_) {}
            }

            // Instant narrative first — popup never waits on a blank spinner.
            const local = buildLocalSummaryFromRow(row);
            showSummaryResult(local);
            setSummaryLoading(true, force ? 'Refreshing AI summary…' : 'Polishing with AI…');
            if (summaryRegen) summaryRegen.disabled = true;

            try {
                const res = await fetch(summaryUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        call_log_ref: row.dataset.callLogRef || '',
                        call_uuid: row.dataset.callUuid || '',
                        force: Boolean(force),
                    }),
                });
                if (requestToken !== summaryRequestToken || activeSummaryRow !== row) return;
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.summary) {
                    throw new Error(data.message || 'Could not generate AI call recording summary.');
                }
                showSummaryResult(data);
            } catch (err) {
                if (requestToken !== summaryRequestToken || activeSummaryRow !== row) return;
                // Keep the instant summary visible; only toast if regenerate failed hard.
                if (force && !plainSummary) {
                    if (summaryError) {
                        summaryError.hidden = false;
                        summaryError.textContent = err?.message || 'Could not generate AI call recording summary.';
                    }
                    window.showToast?.(err?.message || 'Could not generate AI call recording summary.', 'error');
                }
            } finally {
                if (requestToken === summaryRequestToken) {
                    setSummaryLoading(false);
                }
            }
        }

        function downloadSummaryText() {
            const content = downloadDoc || buildDownloadDoc({
                ...summaryMetaPayload,
                summary: plainSummary,
            });
            if (!content.trim()) return;
            const phone = String(summaryMetaPayload.to_phone || summaryMetaPayload.phone || 'call')
                .replace(/[^\dA-Za-z]+/g, '_')
                .replace(/^_|_$/g, '') || 'call';
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            const objectUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = objectUrl;
            a.download = `call-summary_${phone}.txt`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 1500);
            window.showToast?.('Summary download started.', 'success');
        }

        closeBtn?.addEventListener('click', closePlayer);
        summaryClose?.addEventListener('click', (e) => { e.preventDefault(); closeSummaryModal(); });
        summaryModal?.addEventListener('click', (event) => {
            if (event.target === summaryModal) closeSummaryModal();
        });
        talkOpenBtn?.addEventListener('click', (e) => { e.preventDefault(); openTalkModal(); });
        talkCloseBtn?.addEventListener('click', (e) => { e.preventDefault(); closeTalkModal(); });
        talkModal?.addEventListener('click', (event) => {
            if (event.target === talkModal) closeTalkModal();
        });
        summaryDownload?.addEventListener('click', (e) => { e.preventDefault(); downloadSummaryText(); });
        summaryRegen?.addEventListener('click', (e) => {
            e.preventDefault();
            if (activeSummaryRow) void fetchAiSummary(activeSummaryRow, true);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (summaryModal?.classList.contains('is-open')) {
                closeSummaryModal();
                return;
            }
            if (talkModal?.classList.contains('is-open')) {
                closeTalkModal();
            }
        });

        root.addEventListener('click', async (event) => {
            const summaryBtn = event.target.closest('[data-ai-summary-btn]');
            if (summaryBtn) {
                event.preventDefault();
                event.stopPropagation();
                const row = summaryBtn.closest('[data-call-log-row]');
                if (row) await fetchAiSummary(row, false);
                return;
            }

            const playBtn = event.target.closest('[data-recording-play]');
            const downloadBtn = event.target.closest('[data-recording-download]');
            const syncBtn = event.target.closest('[data-recording-sync]');
            const row = event.target.closest('[data-call-log-row]');
            if (!row) return;

            if (playBtn) {
                event.preventDefault();
                await playUrl(row.dataset.playUrl || '', playBtn);
                return;
            }

            if (downloadBtn) {
                event.preventDefault();
                const phone = (row.dataset.toPhone || 'call').trim().replace(/\W+/g, '_');
                await downloadRecording(row.dataset.downloadUrl || row.dataset.playUrl || '', `recording_${phone}.mp3`, downloadBtn);
                return;
            }

            if (!syncBtn || !syncUrl) return;
            event.preventDefault();
            const cell = row.querySelector('[data-recording-cell]');
            const label = cell?.querySelector('[data-recording-label]');
            if (syncBtn.dataset.syncBusy === '1') return;
            syncBtn.dataset.syncBusy = '1';
            syncBtn.disabled = true;
            syncBtn.classList.add('is-loading');
            if (label) label.textContent = '';
            try {
                const res = await fetch(syncUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        call_log_ref: row.dataset.callLogRef || '',
                        call_uuid: row.dataset.callUuid || '',
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    syncBtn.disabled = false;
                    syncBtn.classList.remove('is-loading');
                    syncBtn.dataset.syncBusy = '';
                    const errMsg = data.message || (res.status === 403
                        ? 'You cannot access this recording.'
                        : 'Could not look up recording. Refresh and try again.');
                    window.showToast?.(errMsg, 'error');
                    return;
                }
                if (data.has_recording && data.play_url) {
                    renderReady(cell, data.play_url, data.download_url || toDownloadUrl(data.play_url));
                    row.dataset.hasRecording = '1';
                    row.dataset.playUrl = data.play_url;
                    row.dataset.downloadUrl = data.download_url || toDownloadUrl(data.play_url);
                    const newPlayBtn = cell.querySelector('[data-recording-play]');
                    await playUrl(data.play_url, newPlayBtn);
                    window.showToast?.('Recording ready.', 'success');
                } else {
                    syncBtn.disabled = false;
                    syncBtn.classList.remove('is-loading');
                    syncBtn.dataset.syncBusy = '';
                    const status = data.recording_status || '';
                    if (label) {
                        label.textContent = status === 'pending' ? 'Pending' : (status === 'unavailable' ? 'None' : 'Not found');
                    }
                    const toastType = status === 'pending' ? 'warning' : 'info';
                    window.showToast?.(
                        data.message || (status === 'pending'
                            ? 'Recording is still processing. Try Find again in a moment.'
                            : 'No recording was found for this call.'),
                        toastType,
                    );
                }
            } catch (_) {
                syncBtn.disabled = false;
                syncBtn.classList.remove('is-loading');
                syncBtn.dataset.syncBusy = '';
                window.showToast?.('Could not look up recording.', 'error');
            }
        });

        async function autoFindVisible() {
            // Disabled: auto Find on page load was hammering recording APIs.
            // Users can click Find when needed — keeps the server healthy.
        }

        // void autoFindVisible();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllCallLogsSummary, { once: true });
    } else {
        initAllCallLogsSummary();
    }
    document.addEventListener('turbo:load', initAllCallLogsSummary);
    document.addEventListener('turbo:render', initAllCallLogsSummary);
})();
</script>
@endpush
