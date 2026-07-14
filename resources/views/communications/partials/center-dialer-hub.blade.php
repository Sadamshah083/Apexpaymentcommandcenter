@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $compact = $compact ?? false;
    $autoDialer = (bool) ($hubAccess['canAutoDial'] ?? false);
    $canViewTeamRecordings = (bool) ($hubAccess['canViewTeamRecordings'] ?? false);
    $isAgentDialer = ($hubAccess['tier'] ?? '') === 'agent';
    $callLogsPerPage = (int) config('integrations.communications.list_page_size', 20);
    $allCallLogs = collect($callLogs ?? []);
    $recentLogs = $allCallLogs->take($callLogsPerPage);
    $liveCalls = collect($activeCalls ?? [])->take(5);
    $hasMoreCallLogs = (bool) ($dialerCallLogsHasMore ?? $allCallLogs->count() > $callLogsPerPage);
    $callLogsApiUrl = url((request()->is('admin*') ? '/admin' : '/portal') . '/communications/dialer/call-logs');
    $recordingSyncUrl = url((request()->is('admin*') ? '/admin' : '/portal') . '/communications/dialer/call-logs/recording/sync');
    $importedLeads = collect($dialerImportedLeads ?? []);
    $leadsPageSize = (int) config('integrations.communications.dialer_leads_page_size', 25);
    $hasMoreImportedLeads = (bool) ($dialerImportedLeadsHasMore ?? $importedLeads->count() > $leadsPageSize);
    $importedLeadsApiUrl = $autoDialer ? route($routePrefix . 'communications.dialer.imported-leads') : '';
    $presenceUrl = route($routePrefix . 'communications.monitoring.presence');
    $campaignOptions = collect($dialerCampaignOptions ?? []);
    $recordingLogs = $allCallLogs
        ->filter(fn ($log) => ! empty($log['has_recording_media']) && ! empty($log['recording_id']))
        ->values();
    $recentRecordings = $recordingLogs->take($callLogsPerPage);
    $hasMoreRecordings = $recordingLogs->count() > $callLogsPerPage;
@endphp

<div class="ch-dial-workspace ch-dial-workspace--compact ghl-dialer-center {{ $compact ? 'ghl-dialer-center--compact' : '' }} {{ $autoDialer ? 'ch-dial-workspace--admin' : '' }}"
    data-phone-workspace
    data-presence-url="{{ $presenceUrl }}"
    data-phone-view="{{ $autoDialer ? 'logs' : 'dialer' }}"
    data-recording-role=""
    data-recording-sync-url="{{ $recordingSyncUrl }}"
    @if ($autoDialer) data-auto-dial-hub data-imported-leads-url="{{ $importedLeadsApiUrl }}" @endif>
    <div class="ch-dial-workspace__toolbar">
        <div class="ch-dial-workspace__nav" data-phone-workspace-nav>
            @if ($autoDialer)
                <div class="ghl-comm-dial-mode" data-dial-mode-switch role="tablist" aria-label="Dial mode">
                    <button type="button" class="ghl-comm-dial-mode__btn is-active" data-dial-mode="manual" role="tab" aria-selected="true">
                        Manual dial
                    </button>
                    <button type="button" class="ghl-comm-dial-mode__btn" data-dial-mode="auto" role="tab" aria-selected="false">
                        Auto dial
                    </button>
                </div>
            @endif
            <div class="ghl-phone-panel-switch" data-phone-panel-switch role="tablist" aria-label="Phone workspace">
                @if ($autoDialer)
                    <button type="button" class="ghl-phone-panel-switch__btn is-active" data-phone-panel-view="logs"
                        role="tab" aria-selected="true" aria-controls="ghl-phone-logs-pane-center">Call logs</button>
                    <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="leads"
                        role="tab" aria-selected="false" aria-controls="ghl-phone-leads-pane-center">Imported leads</button>
                    <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="recordings" data-recording-role=""
                        role="tab" aria-selected="false" aria-controls="ghl-phone-recordings-pane-center">Call Recording</button>
                    @if ($canViewTeamRecordings)
                        <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="recordings" data-recording-role="agent"
                            role="tab" aria-selected="false" aria-controls="ghl-phone-recordings-pane-center">Agent recordings</button>
                        <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="recordings" data-recording-role="team_lead"
                            role="tab" aria-selected="false" aria-controls="ghl-phone-recordings-pane-center">Team lead recordings</button>
                    @endif
                @else
                    <button type="button" class="ghl-phone-panel-switch__btn is-active" data-phone-panel-view="logs"
                        role="tab" aria-selected="true" aria-controls="ghl-phone-logs-pane-center">Call logs</button>
                    <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="recordings" data-recording-role=""
                        role="tab" aria-selected="false" aria-controls="ghl-phone-recordings-pane-center">Call Recording</button>
                    @if ($canViewTeamRecordings)
                        <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="recordings" data-recording-role="agent"
                            role="tab" aria-selected="false" aria-controls="ghl-phone-recordings-pane-center">Agent recordings</button>
                        <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="recordings" data-recording-role="team_lead"
                            role="tab" aria-selected="false" aria-controls="ghl-phone-recordings-pane-center">Team lead recordings</button>
                    @endif
                    <button type="button" class="ghl-phone-panel-switch__btn" data-phone-panel-view="dialer"
                        role="tab" aria-selected="false" aria-controls="ghl-phone-dial-pane-center">Dial pad</button>
                @endif
            </div>
        </div>
        @if ($autoDialer)
            <p class="ch-auto-dial-countdown hidden" data-auto-dial-countdown aria-live="polite">
                <span data-auto-dial-countdown-text></span>
            </p>
        @endif
    </div>
    <div class="ch-dial-workspace__grid ch-dial-workspace__grid--phone-split {{ $autoDialer ? 'ch-dial-workspace__grid--admin-split' : '' }}">
        <div class="ch-dial-workspace__left-pane {{ $autoDialer ? 'ch-dial-workspace__left-pane--admin' : '' }}"
            @if ($autoDialer) data-phone-left-pane @endif>
        <aside class="ch-panel ghl-dialer-center-logs ghl-dialer-center-logs--full is-visible" id="ghl-phone-logs-pane-center"
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

        @if ($autoDialer)
            <aside class="ch-panel ghl-dialer-center-leads ghl-dialer-center-logs ghl-dialer-center-logs--full hidden" id="ghl-phone-leads-pane-center"
                data-phone-leads-pane role="tabpanel" aria-label="Imported leads">
                <div class="ghl-dialer-center-logs__header ch-panel__header ch-panel__header--slim">
                    <h3 class="ch-panel__title">Imported leads</h3>
                    <p class="ghl-dialer-leads-status hidden" data-auto-dial-status aria-live="polite"></p>
                </div>
                <div class="ghl-dialer-leads-toolbar">
                    <div class="ghl-dialer-leads-filters">
                        <div class="ghl-dialer-leads-field">
                            <span class="ghl-dialer-leads-label" id="dialer-leads-pool-label">Lead pool</span>
                            <div class="ghl-leads-select" data-leads-select>
                                <select class="ghl-leads-select__native" data-dialer-leads-pool
                                    aria-labelledby="dialer-leads-pool-label">
                                    @if ($isAgentDialer)
                                        <option value="assigned" selected>My assigned leads</option>
                                        <option value="callable">My callable leads</option>
                                        <option value="all">All my leads with phone</option>
                                    @else
                                        <option value="callable" selected>Callable leads</option>
                                        <option value="all">All with phone</option>
                                        <option value="unassigned">Unassigned</option>
                                        <option value="assigned">Assigned</option>
                                    @endif
                                </select>
                                <button type="button" class="ghl-leads-select__trigger" aria-haspopup="listbox"
                                    aria-expanded="false" aria-labelledby="dialer-leads-pool-label">
                                    <span class="ghl-leads-select__value" data-leads-select-value>Callable leads</span>
                                    <svg class="ghl-leads-select__chevron" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                                <div class="ghl-leads-select__menu" hidden role="listbox"
                                    aria-labelledby="dialer-leads-pool-label"></div>
                            </div>
                        </div>
                        @if ($campaignOptions->isNotEmpty())
                            <div class="ghl-dialer-leads-field">
                                <span class="ghl-dialer-leads-label" id="dialer-leads-campaign-label">Campaign</span>
                                <div class="ghl-leads-select" data-leads-select>
                                    <select class="ghl-leads-select__native" data-dialer-leads-campaign
                                        aria-labelledby="dialer-leads-campaign-label">
                                        <option value="">All campaigns</option>
                                        @foreach ($campaignOptions as $campaign)
                                            <option value="{{ $campaign['id'] }}">{{ $campaign['name'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="ghl-leads-select__trigger" aria-haspopup="listbox"
                                        aria-expanded="false" aria-labelledby="dialer-leads-campaign-label">
                                        <span class="ghl-leads-select__value" data-leads-select-value>All campaigns</span>
                                        <svg class="ghl-leads-select__chevron" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                    <div class="ghl-leads-select__menu" hidden role="listbox"
                                        aria-labelledby="dialer-leads-campaign-label"></div>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="ghl-dialer-leads-actions">
                        <button type="button" class="ch-btn ch-btn--primary ghl-auto-dial-btn" data-auto-dial-start>
                            Start auto dial
                        </button>
                        <button type="button" class="ch-btn ch-btn--secondary ghl-auto-dial-btn hidden" data-auto-dial-stop>
                            Stop auto dial
                        </button>
                    </div>
                </div>
                <div class="ghl-dialer-center-logs__scroll ch-panel__body ch-panel__body--slim ghl-dialer-recent-list ghl-dialer-recent-list--full"
                    data-imported-leads-list
                    data-imported-leads-url="{{ $importedLeadsApiUrl }}"
                    data-imported-leads-offset="{{ $importedLeads->count() }}"
                    data-imported-leads-has-more="{{ $hasMoreImportedLeads ? '1' : '0' }}">
                    <div data-imported-leads-items>
                        @forelse ($importedLeads as $lead)
                            @include('communications.partials.dialer-lead-row', ['lead' => $lead])
                        @empty
                            <p class="ghl-dialer-recent-empty" data-imported-leads-empty>
                                {{ $isAgentDialer ? 'No leads assigned to you with phone numbers yet.' : 'No imported leads with phone numbers yet.' }}
                            </p>
                        @endforelse
                    </div>
                    <p class="ghl-dialer-recent-loading hidden" data-imported-leads-loading aria-live="polite">Loading more leads…</p>
                    <div class="ghl-dialer-recent-sentinel" data-imported-leads-sentinel aria-hidden="true"></div>
                </div>
            </aside>
        @endif

            <aside class="ch-panel ghl-dialer-center-recordings ghl-dialer-center-logs ghl-dialer-center-logs--full hidden" id="ghl-phone-recordings-pane-center"
                data-phone-recordings-pane role="tabpanel" aria-label="Call recordings">
                <div class="ghl-dialer-center-logs__header ch-panel__header ch-panel__header--slim">
                    <h3 class="ch-panel__title" data-phone-recordings-title>Call Recording</h3>
                </div>
                <div class="ghl-dialer-center-logs__scroll ch-panel__body ch-panel__body--slim ghl-dialer-recent-list ghl-dialer-recent-list--full"
                    data-call-recordings-list
                    data-call-logs-url="{{ $callLogsApiUrl }}"
                    data-recording-role=""
                    data-call-recordings-offset="{{ $recentRecordings->count() }}"
                    data-call-recordings-has-more="{{ $hasMoreRecordings ? '1' : '0' }}">
                    <div data-call-recordings-items>
                        @forelse ($recentRecordings as $log)
                            @include('communications.partials.dialer-recording-row', [
                                'log' => $log,
                                'routePrefix' => $routePrefix,
                            ])
                        @empty
                            <p class="ghl-dialer-recent-empty" data-call-recordings-empty>No call recordings yet.</p>
                        @endforelse
                    </div>
                    <p class="ghl-dialer-recent-loading hidden" data-call-recordings-loading aria-live="polite">Loading more recordings…</p>
                    <div class="ghl-dialer-recent-sentinel" data-call-recordings-sentinel aria-hidden="true"></div>
                </div>
            </aside>
        </div>

        <div class="ghl-dialer-center-keypad ghl-dialer-center-keypad--borderless ghl-dialer-center-keypad--full {{ $autoDialer ? 'is-visible' : '' }}"
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
                    <div class="ghl-phone-recording-card">
                        <button type="button" class="ghl-phone-back-btn" data-phone-back-dialer>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="15 18 9 12 15 6"></polyline>
                            </svg>
                            Back to dialer
                        </button>
                        <h3 class="ghl-phone-recording-title">Call recording</h3>
                        <div class="ghl-phone-recording-meta" data-phone-recording-meta></div>
                        <div class="ghl-phone-recording-player hidden" data-phone-recording-player>
                            <audio class="ghl-phone-recording-audio" controls preload="none"
                                playsinline data-phone-recording-audio></audio>
                        </div>
                        <p class="ghl-phone-recording-empty hidden" data-phone-recording-empty>
                            No recording is available for this call yet.
                        </p>
                        <div class="ghl-phone-recording-actions hidden" data-phone-recording-actions>
                            <button type="button" class="ghl-phone-recording-play" data-phone-recording-play
                                title="Play recording">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <polygon points="5 3 19 12 5 21 5 3" />
                                </svg>
                                <span>Play</span>
                            </button>
                            <a href="#" class="ghl-phone-recording-download" data-phone-recording-download
                                download title="Download recording">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="7 10 12 15 17 10" />
                                    <line x1="12" y1="15" x2="12" y2="3" />
                                </svg>
                                <span>Download</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('communications.partials.call-summary-modal')
</div>
