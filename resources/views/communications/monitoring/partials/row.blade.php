@php
    $group = $row['status_group'] ?? 'ringing';
    $bucket = $row['bucket'] ?? ($group === 'incall'
        ? (((int) ($row['timer_sec'] ?? 0)) > 120 ? 'incall_long' : 'incall_short')
        : $group);
    $timerSec = (int) ($row['timer_sec'] ?? 0);
    $colorClass = match ($bucket) {
        'ringing', 'waiting' => 'is-waiting',
        'incall_short' => 'is-incall',
        'incall_long' => 'is-incall-long',
        'not_in_call', 'idle' => 'is-idle',
        'queue' => 'is-queue',
        'dead' => 'is-dead',
        default => 'is-waiting',
    };
    $showTimer = in_array($bucket, ['incall_short', 'incall_long', 'not_in_call'], true);
    $idleSince = $row['idle_since'] ?? null;
    $isIdle = $bucket === 'not_in_call';
    $dialMode = strtolower((string) ($row['dial_mode'] ?? ''));
    $dialLabel = trim((string) ($row['dial_mode_label'] ?? ''));
    if ($dialLabel === '' && $isIdle) {
        $dialLabel = trim((string) ($row['destination'] ?? ''));
    }
    if ($dialMode === '' && $dialLabel !== '') {
        $dialMode = str_contains(strtolower($dialLabel), 'auto') ? 'auto' : 'manual';
    }
    $dialPillClass = 'call-monitoring-dial-pill--manual';
    if ($dialMode === 'auto') {
        $dialPillClass = str_contains(strtolower($dialLabel), 'paused')
            ? 'call-monitoring-dial-pill--auto-paused'
            : 'call-monitoring-dial-pill--auto';
    }
    if ($dialLabel === '' && $dialMode !== '') {
        $dialLabel = $dialMode === 'auto' ? 'Auto dial' : 'Manual dial';
    }
@endphp
<tr class="call-monitoring-row {{ $colorClass }}"
    data-row-id="{{ $row['id'] ?? '' }}"
    data-status-group="{{ $group }}"
    data-bucket="{{ $bucket }}"
    data-timer-sec="{{ $showTimer ? $timerSec : 0 }}"
    data-dial-mode="{{ $dialMode !== '' ? $dialMode : '' }}"
    @if ($showTimer && ! empty($row['connected_at'])) data-connected-at="{{ $row['connected_at'] }}" @endif
    @if ($bucket === 'not_in_call' && ! empty($idleSince)) data-idle-since="{{ $idleSince }}" @endif>
    <td class="call-monitoring-row__station">{{ $row['station'] ?? '—' }}</td>
    <td class="call-monitoring-row__user">
        <span class="call-monitoring-row__name">{{ $row['user'] ?? '—' }}</span>
        @if (! empty($row['role_label']))
            <span class="call-monitoring-row__role">{{ $row['role_label'] }}</span>
        @endif
    </td>
    <td class="call-monitoring-row__status">
        <span class="call-monitoring-status-pill">{{ $row['status'] ?? '—' }}</span>
    </td>
    <td class="call-monitoring-row__timer" data-row-timer>
        @if ($showTimer)
            {{ $row['timer_label'] ?? sprintf('%02d:%02d', intdiv(max(0, $timerSec), 60), max(0, $timerSec) % 60) }}
        @else
            00:00
        @endif
    </td>
    <td class="call-monitoring-row__dest">
        @if ($isIdle && $dialLabel !== '')
            <span class="call-monitoring-dial-pill {{ $dialPillClass }}">{{ $dialLabel }}</span>
        @elseif (($row['destination'] ?? '') !== '')
            {{ $row['destination'] }}
        @else
            —
        @endif
    </td>
    <td class="call-monitoring-row__campaign">
        @if (! $isIdle && $dialLabel !== '' && (($row['campaign'] ?? '—') === '—' || ($row['campaign'] ?? '') === '' || ($row['campaign'] ?? '') === $dialLabel))
            <span class="call-monitoring-dial-pill {{ $dialPillClass }}">{{ $dialLabel }}</span>
        @else
            {{ $row['campaign'] ?? '—' }}
        @endif
    </td>
</tr>
