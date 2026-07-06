@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $compact = $compact ?? false;
    $defaultExtension = $defaultCallerId ?? config('integrations.communications.default_caller_id');
@endphp

<div class="ch-dial-workspace ghl-dialer-center {{ $compact ? 'ghl-dialer-center--compact' : '' }}">
    <header class="ch-dial-workspace__hero">
        <x-communications.atoms.badge variant="offline" class="ghl-dialer-center-badge" data-dial-hero-badge>
            <span class="ch-status-dot" data-webphone-dot></span>
            <span data-webphone-status-text>Offline</span>
        </x-communications.atoms.badge>
        <h2 class="ch-dial-workspace__title">Outbound dialer</h2>
        <p class="ghl-dialer-center-subtitle">
            Three steps: connect your browser line, enter a number, then talk. Calls route through your Morpheus campaign trunk.
        </p>
    </header>

    <x-communications.molecules.workflow-stepper
        :steps="[
            ['title' => 'Connect line', 'desc' => 'Register your browser phone with Morpheus (once per session).', 'state' => 'is-active'],
            ['title' => 'Dial number', 'desc' => 'Choose your extension and enter the destination.', 'state' => ''],
            ['title' => 'Talk', 'desc' => 'Answer on your browser when prompted; audio routes through this tab.', 'state' => ''],
        ]"
    />

    <div class="ch-dial-workspace__grid">
        <div class="ch-panel ghl-dialer-center-phone" data-workflow-panel="1">
            <div class="ch-panel__header">
                <div>
                    <span class="ch-panel__step-tag">Step 1</span>
                    <h3 class="ch-panel__title">Your browser line</h3>
                </div>
                <span class="ghl-webphone-status" data-webphone-status aria-live="polite">
                    <span class="ch-status-dot" data-webphone-dot-header></span>
                    <span data-webphone-status-text-header>Offline</span>
                </span>
            </div>
            <div class="ch-panel__body">
                @include('communications.partials.webphone-panel', [
                    'routePrefix' => $routePrefix,
                    'defaultCallerId' => $defaultCallerId ?? null,
                    'layout' => 'center',
                ])
            </div>
        </div>

        <div class="ch-panel ghl-dialer-center-keypad" data-workflow-panel="2">
            <div class="ch-panel__header">
                <div>
                    <span class="ch-panel__step-tag">Step 2 &amp; 3</span>
                    <h3 class="ch-panel__title">Dial &amp; call</h3>
                </div>
            </div>
            <div class="ch-panel__body">
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
    </div>

    @if (!empty($callLogs))
        <section class="ghl-dialer-center-logs ch-panel" style="margin-top: var(--ch-space-5);">
            <div class="ch-panel__header ghl-dialer-center-logs-head">
                <h3 class="ch-panel__title ghl-dialer-center-logs-title">Recent calls</h3>
                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'calls']) }}"
                    class="comm-hub-link text-xs">View all</a>
            </div>
            <div class="ghl-dialer-center-logs-scroll">
                <table class="ghl-dialer-logs-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Direction</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Result</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (collect($callLogs)->take(12) as $log)
                            @php
                                $callbackPhone = match ($log['direction'] ?? '') {
                                    'inbound' => $log['from_phone'] ?? null,
                                    'outbound' => $log['to_phone'] ?? null,
                                    default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
                                };
                            @endphp
                            <tr>
                                <td>
                                    @if (!empty($log['start_time']))
                                        {{ \Carbon\Carbon::parse($log['start_time'])->diffForHumans(short: true) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <span class="ghl-tag ghl-tag-{{ $log['direction'] ?? 'unknown' }}">
                                        {{ ucfirst($log['direction'] ?? '—') }}
                                    </span>
                                </td>
                                <td>{{ $log['from_phone'] ?? ($log['from'] ?? '—') }}</td>
                                <td>{{ $log['to_phone'] ?? ($log['to'] ?? '—') }}</td>
                                <td>{{ $log['result'] ?? '—' }}</td>
                                <td class="text-right">
                                    @if ($callbackPhone)
                                        <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm"
                                            data-dial-number="{{ $callbackPhone }}">Call</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
