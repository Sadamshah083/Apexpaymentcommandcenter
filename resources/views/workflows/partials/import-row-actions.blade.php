@php
    $totalLeads = (int) ($totalLeads ?? $wf->total_leads ?? 0);
@endphp

<div class="import-workflows-actions">
    <a href="{{ route('admin.workflows.show', $wf->id) }}" class="app-btn app-btn-secondary app-btn-sm">View</a>
    @if(in_array($wf->status, ['pending', 'extracting']))
        <form method="POST" action="{{ route('admin.workflows.pause', $wf->id) }}">
            @csrf
            <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Pause</button>
        </form>
    @endif
    @if($wf->status === 'paused')
        <form method="POST" action="{{ route('admin.workflows.resume', $wf->id) }}">
            @csrf
            <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Resume</button>
        </form>
    @endif
    @if($wf->status === 'mapping')
        <a href="{{ route('admin.workflows.show', $wf->id) }}" class="app-btn app-btn-secondary app-btn-sm">Setup</a>
    @endif
    <button
        type="button"
        class="app-btn app-btn-ghost-danger app-btn-sm"
        data-import-delete-open
        data-workflow-id="{{ $wf->id }}"
        data-workflow-name="{{ $wf->name }}"
        data-workflow-total="{{ $totalLeads }}"
    >Delete</button>
</div>
