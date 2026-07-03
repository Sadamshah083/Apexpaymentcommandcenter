<tr class="um-workspace-row {{ $ws->id === $activeWorkspace->id ? 'um-workspace-row-active' : '' }}"
    data-workspace-id="{{ $ws->id }}">
    <td class="col-name">
        <span class="um-field-label">Workspace</span>
        <span class="um-workspace-table-name">{{ $ws->name }}</span>
    </td>
    <td class="col-owner">
        <span class="um-field-label">Owner</span>
        <span>{{ $ws->admin->name }}</span>
    </td>
    <td class="col-pipelines">
        <span class="um-field-label">Pipelines</span>
        <span>{{ $ws->workflows_count }}</span>
    </td>
    <td class="col-members">
        <span class="um-field-label">Members</span>
        <span>{{ $ws->users_count }}</span>
    </td>
    <td class="col-status text-right">
        @if ($ws->id === $activeWorkspace->id)
            <span class="um-badge um-badge-active">Active</span>
        @else
            <form method="POST" action="{{ route('admin.workspaces.switch', $ws->id) }}" class="workspace-switch-form">
                @csrf
                <button type="submit" class="um-btn um-btn-ghost um-btn-sm">Switch</button>
            </form>
        @endif
    </td>
</tr>
