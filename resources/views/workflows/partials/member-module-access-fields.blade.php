@php
    use App\Support\AdminModules;

    $moduleGroups = AdminModules::groupedForUi();
    $canGrantUserManagement = auth()->user()?->isSuperAdmin($activeWorkspace->id);
@endphp

<div class="border border-slate-200 rounded-xl p-3 bg-slate-50/80 space-y-3">
    <div>
        <p class="text-xs font-bold text-slate-700">Admin feature access</p>
        <p class="text-[11px] text-slate-500 mt-0.5">Limit which admin modules this account can open.</p>
    </div>

    <div class="flex flex-wrap gap-3 text-xs">
        <label class="inline-flex items-center gap-2">
            <input type="radio" name="access_mode" value="full" checked class="member-access-mode">
            <span>Full access</span>
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="radio" name="access_mode" value="restricted" class="member-access-mode">
            <span>Selected modules only</span>
        </label>
    </div>

    <div class="member-module-grid space-y-3 hidden" data-module-grid>
        @foreach($moduleGroups as $section => $modules)
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400 mb-1.5">{{ $section }}</p>
                <div class="space-y-1.5">
                    @foreach($modules as $module)
                        @if(($module['always_available'] ?? false) || ($module['key'] === 'user_management' && ! $canGrantUserManagement))
                            @continue
                        @endif
                        <label class="flex items-start gap-2 text-xs text-slate-600">
                            <input type="checkbox" name="modules[]" value="{{ $module['key'] }}" class="mt-0.5">
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
</div>
