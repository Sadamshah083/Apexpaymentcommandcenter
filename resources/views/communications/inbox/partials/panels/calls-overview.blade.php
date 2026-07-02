@if (!empty($callStats))
    <div class="ghl-stat-row mb-6">
        <div class="ghl-stat-chip"><span class="ghl-stat-chip-value">{{ $callStats['total'] ?? 0 }}</span><span
                class="ghl-stat-chip-label">Total</span></div>
        <div class="ghl-stat-chip"><span class="ghl-stat-chip-value">{{ $callStats['inbound'] ?? 0 }}</span><span
                class="ghl-stat-chip-label">Inbound</span></div>
        <div class="ghl-stat-chip"><span class="ghl-stat-chip-value">{{ $callStats['outbound'] ?? 0 }}</span><span
                class="ghl-stat-chip-label">Outbound</span></div>
        <div class="ghl-stat-chip"><span class="ghl-stat-chip-value">{{ $callStats['missed'] ?? 0 }}</span><span
                class="ghl-stat-chip-label">Missed</span></div>
        <div class="ghl-stat-chip"><span class="ghl-stat-chip-value">{{ $callStats['recorded'] ?? 0 }}</span><span
                class="ghl-stat-chip-label">Recorded</span></div>
    </div>
@endif
@if (empty($sidebarItems))
    @include('communications.inbox.partials.zoom-data-hint')
@endif
@include('communications.inbox.partials.empty', [
    'title' => 'Select a call',
    'message' => 'Pick a call from the list to see details, play recordings, or call back.',
])
