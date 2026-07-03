@if (!empty($detail))
    <section class="dash-detail-panel" id="dash-detail-panel" data-detail-key="{{ $detail['key'] ?? request('detail') }}">
        <div class="dash-detail-panel-head">
            <div class="dash-detail-panel-title-wrap">
                <a href="{{ route('admin.dashboard') }}" class="dash-detail-back" aria-label="Back to dashboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                    Dashboard
                </a>
                <h2 class="dash-detail-title">{{ $detail['title'] }}</h2>
                <p class="dash-detail-desc">{{ $detail['description'] }}</p>
            </div>
            <div class="dash-detail-panel-meta">
                @if (isset($detail['total']))
                    <div class="dash-detail-count">
                        <span class="dash-detail-count-value" id="detail-total-count">{{ number_format($detail['total']) }}</span>
                        <span class="dash-detail-count-label">records</span>
                    </div>
                @endif
                <span class="dash-detail-live">
                    <span class="dash-detail-live-dot"></span>
                    Live
                </span>
            </div>
        </div>

        @if (!empty($detail['stats']))
            <div class="dash-detail-stats" id="detail-stats-row">
                @foreach ($detail['stats'] as $stat)
                    <div class="dash-detail-stat">
                        <span class="dash-detail-stat-value" id="detail-stat-{{ $loop->index }}">{{ is_numeric($stat['value']) ? number_format($stat['value']) : $stat['value'] }}</span>
                        <span class="dash-detail-stat-label">{{ $stat['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if (!empty($detail['activities']))
            <div class="dash-detail-table-wrap">
                <table class="dash-detail-table">
                    <thead>
                        <tr>
                            <th>Team member</th>
                            <th>Lead</th>
                            <th>Activity</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody id="detail-activities-body">
                        @forelse ($detail['activities'] as $activity)
                            <tr>
                                <td>{{ $activity->user?->name ?? '—' }}</td>
                                <td>
                                    @if ($activity->lead)
                                        <a href="{{ route('admin.workflows.show', $activity->lead->workflow_id) }}?lead={{ $activity->lead_id }}"
                                            class="dash-detail-link">{{ $activity->lead->business_name ?: 'Lead #'.$activity->lead_id }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td><span class="dash-detail-badge">{{ str_replace('_', ' ', $activity->type) }}</span></td>
                                <td class="dash-detail-muted">{{ $activity->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="dash-detail-empty">No activity logged yet today.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($detail['activities']->hasPages())
                <div class="dash-detail-pagination">{{ $detail['activities']->links() }}</div>
            @endif
        @elseif (!empty($detail['leads']))
            @include('admin.dashboard.partials.leads-mini-table', ['leads' => $detail['leads']])
        @endif

        <div class="dash-detail-actions">
            @if (!empty($detail['workflow_show']))
                <a href="{{ $detail['workflow_show'] }}" class="app-btn app-btn-primary app-btn-sm">Open workflow file</a>
            @endif
            @if (!empty($detail['workflows_link']))
                <a href="{{ route('admin.workflows.index', array_filter($detail['workflows_link'])) }}"
                    class="app-btn app-btn-secondary app-btn-sm">View in workflows</a>
            @endif
            @if (($detail['key'] ?? '') === 'performer' && !empty($detail['user']['id']))
                <a href="{{ route('admin.sales-ops.performance') }}"
                    class="app-btn app-btn-secondary app-btn-sm">Full performance report</a>
            @endif
        </div>
    </section>
@endif
