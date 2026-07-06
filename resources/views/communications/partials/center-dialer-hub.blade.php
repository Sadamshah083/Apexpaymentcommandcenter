@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $compact = $compact ?? false;
@endphp

<div class="ghl-dialer-center {{ $compact ? 'ghl-dialer-center--compact' : '' }}">
    <div class="ghl-dialer-center-hero">
        <div class="ghl-dialer-center-badge">
            <span class="ghl-webphone-dot" data-webphone-dot></span>
            <span data-webphone-status-text>Offline</span>
        </div>
        <h2 class="ghl-dialer-center-title">Phone dialer</h2>
        <p class="ghl-dialer-center-subtitle">
            Select any extension you are allowed to use, connect your line, then call. Morpheus places the outbound leg through your campaign trunk.
        </p>
    </div>

    <div class="ghl-dialer-center-grid">
        <div class="ghl-dialer-center-phone">
            @include('communications.partials.webphone-panel', [
                'routePrefix' => $routePrefix,
                'defaultCallerId' => $defaultCallerId ?? null,
                'layout' => 'center',
            ])
        </div>

        <div class="ghl-dialer-center-keypad">
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

    @if (!empty($callLogs))
        <section class="ghl-dialer-center-logs">
            <div class="ghl-dialer-center-logs-head">
                <h3 class="ghl-dialer-center-logs-title">Recent calls</h3>
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
                                        <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm"
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
