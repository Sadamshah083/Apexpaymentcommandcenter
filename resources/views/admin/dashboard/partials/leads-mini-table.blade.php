<div class="dash-detail-table-wrap">
    <table class="dash-detail-table">
        <thead>
            <tr>
                <th>Business</th>
                <th>Phase</th>
                <th>Stage</th>
                <th>Assignee</th>
                <th>Updated</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="detail-leads-body">
            @forelse ($leads as $lead)
                <tr>
                    <td>
                        <span class="dash-detail-lead-name">{{ $lead->business_name ?: $lead->owner_name ?: 'Lead #'.$lead->id }}</span>
                        @if ($lead->workflow)
                            <span class="dash-detail-muted">{{ $lead->workflow->name }}</span>
                        @endif
                    </td>
                    <td><span class="dash-detail-badge">{{ str_replace('_', ' ', $lead->pipeline_phase ?? '—') }}</span></td>
                    <td class="dash-detail-muted">{{ str_replace('_', ' ', $lead->stage ?? '—') }}</td>
                    <td>{{ $lead->assignee?->name ?? $lead->setter?->name ?? $lead->closer?->name ?? '—' }}</td>
                    <td class="dash-detail-muted">{{ $lead->updated_at?->diffForHumans(short: true) }}</td>
                    <td class="text-right">
                        <a href="{{ route('admin.workflows.show', $lead->workflow_id) }}?lead={{ $lead->id }}"
                            class="dash-detail-link">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="dash-detail-empty">No matching records in this view.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($leads->hasPages())
    <div class="dash-detail-pagination">{{ $leads->links() }}</div>
@endif
