@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $compact = $compact ?? false;
    $defaultExtension = $defaultCallerId ?? config('integrations.communications.default_caller_id');
    $recentLogs = collect($callLogs ?? [])->take(8);
    $liveCalls = collect($activeCalls ?? [])->take(5);
@endphp

<div class="ch-dial-workspace ch-dial-workspace--compact ghl-dialer-center {{ $compact ? 'ghl-dialer-center--compact' : '' }}">
    <header class="ch-dial-workspace__bar">
        <div class="ch-dial-workspace__bar-main">
            <h2 class="ch-dial-workspace__title">Phone</h2>
            <x-communications.atoms.badge variant="offline" class="ghl-dialer-center-badge" data-dial-hero-badge>
                <span class="ch-status-dot" data-webphone-dot></span>
                <span data-webphone-status-text>Offline</span>
            </x-communications.atoms.badge>
        </div>
        <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'calls']) }}"
            class="comm-hub-link text-xs">Call history</a>
    </header>

    <x-communications.molecules.workflow-stepper
        class="ch-workflow--compact"
        :steps="[
            ['title' => 'Connect', 'desc' => 'Register line', 'state' => 'is-active'],
            ['title' => 'Dial', 'desc' => 'Enter number', 'state' => ''],
            ['title' => 'Talk', 'desc' => 'Handle call', 'state' => ''],
        ]"
    />

    <div class="ch-dial-workspace__grid">
        <div class="ch-panel ghl-dialer-center-phone">
            <div class="ch-panel__header ch-panel__header--slim">
                <h3 class="ch-panel__title">Line</h3>
                <span class="ghl-webphone-status" data-webphone-status aria-live="polite">
                    <span class="ch-status-dot" data-webphone-dot-header></span>
                    <span data-webphone-status-text-header>Offline</span>
                </span>
            </div>
            <div class="ch-panel__body ch-panel__body--slim">
                @include('communications.partials.webphone-panel', [
                    'routePrefix' => $routePrefix,
                    'defaultCallerId' => $defaultCallerId ?? null,
                    'layout' => 'center',
                ])
            </div>
        </div>

        <div class="ch-panel ghl-dialer-center-keypad">
            <div class="ch-panel__header ch-panel__header--slim">
                <h3 class="ch-panel__title">Dial</h3>
            </div>
            <div class="ch-panel__body ch-panel__body--slim">
                @include('communications.partials.dialer-form', [
                    'routePrefix' => $routePrefix,
                    'callerSelectId' => $callerSelectId ?? 'dial-caller-id-center',
                    'numberInputId' => $numberInputId ?? 'dial-number-center',
                    'dialBtnId' => $dialBtnId ?? 'morpheus-dial-btn-center',
                    'backspaceId' => $backspaceId ?? 'dial-backspace-center',
                    'keypadRootId' => $keypadRootId ?? 'dial-keypad-center',
                    'prefillNumber' => $prefillNumber ?? '',
                    'phoneUsers' => $phoneUsers ?? [],
                    'morpheusExtensions' => $morpheusExtensions ?? [],
                    'defaultCallerId' => $defaultCallerId ?? null,
                    'clickToCall' => $clickToCall ?? null,
                    'layout' => 'center',
                ])
            </div>
        </div>

        <aside class="ch-panel ghl-dialer-center-recent">
            <div class="ch-panel__header ch-panel__header--slim">
                <h3 class="ch-panel__title">Recent</h3>
                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'calls']) }}"
                    class="comm-hub-link text-xs">All</a>
            </div>
            <div class="ch-panel__body ch-panel__body--slim ghl-dialer-recent-list">
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

                @forelse ($recentLogs as $log)
                    @php
                        $callbackPhone = match ($log['direction'] ?? '') {
                            'inbound' => $log['from_phone'] ?? null,
                            'outbound' => $log['to_phone'] ?? null,
                            default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
                        };
                    @endphp
                    <div class="ghl-dialer-recent-row">
                        <div class="ghl-dialer-recent-main">
                            <span class="ghl-dialer-recent-dir">{{ ucfirst($log['direction'] ?? 'call') }}</span>
                            <span class="ghl-dialer-recent-number">{{ $callbackPhone ?? '—' }}</span>
                            <span class="ghl-dialer-recent-meta">
                                {{ !empty($log['start_time']) ? \Carbon\Carbon::parse($log['start_time'])->diffForHumans(short: true) : '—' }}
                                · {{ $log['result'] ?? '—' }}
                            </span>
                        </div>
                        @if ($callbackPhone)
                            <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm"
                                data-dial-number="{{ $callbackPhone }}">Call</button>
                        @endif
                    </div>
                @empty
                    <p class="ghl-dialer-recent-empty">No recent calls yet.</p>
                @endforelse
            </div>
        </aside>
    </div>
</div>
