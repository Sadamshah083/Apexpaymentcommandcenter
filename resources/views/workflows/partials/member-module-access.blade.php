@php
    use App\Support\AdminModules;
    use App\Support\SalesOps;

    $moduleGroups = AdminModules::groupedForUi();
    $currentUser = auth()->user();
    $canGrantUserManagement = $currentUser?->isSuperAdmin($activeWorkspace->id);
    $selectedModules = $member->getModulePermissions($activeWorkspace->id) ?? [];
    $isRestricted = $member->usesRestrictedModuleAccess($activeWorkspace->id);
    $memberRole = $member->pivot->role ?? 'appointment_setter';
    $showModuleAccess = SalesOps::isAdminPortalRole($memberRole) && $memberRole !== 'super_admin';
@endphp

@if($showModuleAccess)
    <form
        method="POST"
        action="{{ route('admin.workspaces.members.modules', [$activeWorkspace->id, $member->id]) }}"
        data-member-action="modules"
        data-member-name="{{ $member->name }}"
        class="member-module-access border border-slate-200 rounded-xl p-3 bg-slate-50/80 space-y-3"
    >
        @csrf
        @method('PATCH')

        <div>
            <p class="text-xs font-bold text-slate-700">Feature access</p>
            <p class="text-[11px] text-slate-500 mt-0.5">Choose which admin modules this user can open.</p>
        </div>

        <div class="flex flex-wrap gap-3 text-xs">
            <label class="inline-flex items-center gap-2">
                <input
                    type="radio"
                    name="access_mode"
                    value="full"
                    @checked(! $isRestricted)
                    class="member-access-mode"
                >
                <span>Full access</span>
            </label>
            <label class="inline-flex items-center gap-2">
                <input
                    type="radio"
                    name="access_mode"
                    value="restricted"
                    @checked($isRestricted)
                    class="member-access-mode"
                >
                <span>Selected modules only</span>
            </label>
        </div>

        <div class="member-module-grid space-y-3 {{ $isRestricted ? '' : 'hidden' }}" data-module-grid>
            @foreach($moduleGroups as $section => $modules)
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400 mb-1.5">{{ $section }}</p>
                    <div class="space-y-1.5">
                        @foreach($modules as $module)
                            @if($module['key'] === 'user_management' && ! $canGrantUserManagement)
                                @continue
                            @endif
                            <label class="flex items-start gap-2 text-xs text-slate-600">
                                <input
                                    type="checkbox"
                                    name="modules[]"
                                    value="{{ $module['key'] }}"
                                    @checked(in_array($module['key'], $selectedModules, true))
                                    class="mt-0.5"
                                >
                                <span>
                                    <span class="font-medium text-slate-700">{{ $module['label'] }}</span>
                                    @if($module['description'])
                                        <span class="block text-[11px] text-slate-400">{{ $module['description'] }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <button type="submit" class="member-action-btn member-action-btn-role text-xs">Save module access</button>
    </form>
@endif
