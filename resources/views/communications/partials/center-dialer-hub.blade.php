@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $compact = $compact ?? false;
    $callLogsPerPage = (int) config('integrations.communications.list_page_size', 20);
    $allCallLogs = collect($callLogs ?? []);
    $recentLogs = $allCallLogs->take($callLogsPerPage);
    $liveCalls = collect($activeCalls ?? [])->take(5);
    $hasMoreCallLogs = (bool) ($dialerCallLogsHasMore ?? $allCallLogs->count() > $callLogsPerPage);
    $callLogsApiUrl = url((request()->is('admin*') ? '/admin' : '/portal') . '/communications/dialer/call-logs');
    $recordingSyncUrl = url((request()->is('admin*') ? '/admin' : '/portal') . '/communications/dialer/call-logs/recording/sync');
@endphp

<div class="ch-dial-workspace ch-dial-workspace--compact ghl-dialer-center {{ $compact ? 'ghl-dialer-center--compact' : '' }}"
    data-phone-workspace data-phone-view="dialer" data-recording-sync-url="{{ $recordingSyncUrl }}">
    <div class="ghl-phone-panel-switch" data-phone-panel-switch role="tablist" aria-label="Phone workspace">
        <button type="button" class="ghl-phone-panel-switch__btn is-active" data-phone-panel-view="dialer"
            role="tab" aria-selected="true" aria-controls="ghl-phone-dial-pane-center">Dial pad</button>
        <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="logs"
            role="tab" aria-selected="false" aria-controls="ghl-phone-logs-pane-center">Call logs</button>
    </div>
    <div class="ch-dial-workspace__grid ch-dial-workspace__grid--phone-split">
        <aside class="ch-panel ghl-dialer-center-logs ghl-dialer-center-logs--full" id="ghl-phone-logs-pane-center"
            data-phone-logs-pane role="tabpanel" aria-label="Call logs">
            <div class="ghl-dialer-center-logs__header ch-panel__header ch-panel__header--slim">
                <h3 class="ch-panel__title">Call logs</h3>
            </div>
            <div class="ghl-dialer-center-logs__scroll ch-panel__body ch-panel__body--slim ghl-dialer-recent-list ghl-dialer-recent-list--full"
                data-call-logs-list
                data-call-logs-url="{{ $callLogsApiUrl }}"
                data-call-logs-offset="{{ $recentLogs->count() }}"
                data-call-logs-has-more="{{ $hasMoreCallLogs ? '1' : '0' }}">
                @if ($liveCalls->isNotEmpty())
                    <div class="ghl-dialer-live-block">
                        <p class="ghl-dialer-live-title">Live now ({{ $liveCalls->count() }})</p>
                        @foreach ($liveCalls as $row)
                            @php
                                $dest = $row['destination_number'] ?? $row['destination'] ?? $row['to'] ?? null;
                                $src = $row['caller_number'] ?? $row['from'] ?? null;
                                $state = strtoupper((string) ($row['state'] ?? $row['status'] ?? 'LIVE'));
                                $uuid = $row['call_uuid'] ?? $row['uuid'] ?? null;
                            @endphp
                            <div class="ghl-dialer-live-row">
                                <div class="ghl-dialer-live-main">
                                    <span class="ghl-dialer-live-state">{{ $state }}</span>
                                    <span class="ghl-dialer-live-path">{{ ($src ?: '—') . ' → ' . ($dest ?: '—') }}</span>
                                </div>
                                @if ($uuid)
                                    <a class="comm-hub-link text-[11px]"
                                        href="{{ route($routePrefix . 'communications.index', ['panel' => 'call', 'call' => $uuid, 'channel' => 'calls']) }}">
                                        Open
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div data-call-logs-items>
                    @forelse ($recentLogs as $log)
                        @include('communications.partials.call-log-row', [
                            'log' => $log,
                            'routePrefix' => $routePrefix,
                        ])
                    @empty
                        <p class="ghl-dialer-recent-empty" data-call-logs-empty>No recent calls yet.</p>
                    @endforelse
                </div>

                <p class="ghl-dialer-recent-loading hidden" data-call-logs-loading aria-live="polite">Loading more calls…</p>
                <div class="ghl-dialer-recent-sentinel" data-call-logs-sentinel aria-hidden="true"></div>
            </div>
        </aside>

        <div class="ghl-dialer-center-keypad ghl-dialer-center-keypad--borderless ghl-dialer-center-keypad--full"
            id="ghl-phone-dial-pane-center" data-phone-dial-pane-wrap role="tabpanel" aria-label="Dial pad">
            <div class="ghl-phone-right-pane" data-phone-right-pane>
                <div class="ghl-phone-dial-pane" data-phone-dial-pane>
                    <div class="ch-panel__body ch-panel__body--slim ch-panel__body--phone">
                        @include('communications.partials.dialer-form', [
                            'routePrefix' => $routePrefix,
                            'callerSelectId' => $callerSelectId ?? 'dial-caller-id-center',
                            'numberInputId' => $numberInputId ?? 'dial-number-center',
                            'dialBtnId' => $dialBtnId ?? 'morpheus-dial-btn-center',
                            'backspaceId' => $backspaceId ?? 'dial-backspace-center',
                            'keypadRootId' => $keypadRootId ?? 'dial-keypad-center',
                            'prefillNumber' => $prefillNumber ?? null,
                            'phoneUsers' => $phoneUsers ?? [],
                            'morpheusExtensions' => $morpheusExtensions ?? [],
                            'defaultCallerId' => $defaultCallerId ?? null,
                            'clickToCall' => $clickToCall ?? null,
                            'layout' => 'center',
                            'hideExtension' => true,
                            'formId' => 'dial-caller-id-center-form',
                        ])
                    </div>
                </div>

                <div class="ghl-phone-recording-pane hidden" data-phone-recording-pane>
                    <button type="button" class="ghl-phone-back-btn" data-phone-back-dialer>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                        Back to dialer
                    </button>
                    <h3 class="ghl-phone-recording-title">Call recording</h3>
                    <div class="ghl-phone-recording-meta" data-phone-recording-meta></div>
                    <audio class="ghl-phone-recording-audio hidden" controls preload="none"
                        data-phone-recording-audio></audio>
                    <p class="ghl-phone-recording-empty hidden" data-phone-recording-empty>
                        No recording is available for this call yet.
                    </p>
                    <div class="ghl-phone-recording-actions hidden" data-phone-recording-actions>
                        <a href="#" class="ghl-comm-btn ghl-comm-btn--secondary" data-phone-recording-download
                            download>Download</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
