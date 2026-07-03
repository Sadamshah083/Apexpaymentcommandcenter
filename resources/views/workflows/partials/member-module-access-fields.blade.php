@php
    use App\Support\MemberModuleAccess;

    $workspaceId = $activeWorkspace->id ?? auth()->user()?->current_workspace_id;
    $memberRole = old('role', 'admin');
@endphp

@include('workflows.partials.module-access-form-fields', [
    'selectedMode' => 'full',
    'selectedModules' => [],
    'workspaceId' => $workspaceId,
    'memberRole' => $memberRole,
    'moduleGroups' => MemberModuleAccess::groupedForCreateForm(auth()->user(), (int) $workspaceId),
    'isCreateForm' => true,
])
