@php
    $agents = $communicationAgents ?? [];
    $suggestedExt = $suggestedExtensionNum ?? '1001';
    $sipHost = config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host');
    $provisioned = session('provisioned_agent');
@endphp

@if ($provisioned)
    <div class="comm-hub-alert comm-hub-alert-success mb-4 text-sm">
        <p class="font-semibold mb-1">Phone line created for {{ $provisioned['name'] }}</p>
        <p>Extension: <strong>{{ $provisioned['extension_num'] }}</strong></p>
        <p>SIP password: <strong>{{ $provisioned['sip_password'] }}</strong> <span class="text-xs text-slate-500">(copy
                now — not shown again)</span></p>
        <p class="text-xs mt-2">Softphone: register to <code>{{ $sipHost }}</code> with username
            <code>{{ $provisioned['extension_num'] }}</code>
        </p>
    </div>
@endif

@if ($hubAccess['canConfigure'] ?? false)
<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Provision phone agent</h3>
    <p class="text-sm text-slate-500 mb-3">Creates a Morpheus SIP extension linked to a workspace user. Set the
        <strong>Caller ID number</strong> to the DID Morpheus assigns — outbound calls will not show your business
        number until this is filled in.</p>
    <form method="POST"
        action="{{ route($routePrefix . 'communications.morpheus.agents.provision', ['user' => '__USER__']) }}"
        id="provision-agent-form" class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
        @csrf
        <div class="md:col-span-2">
            <label class="comm-hub-label">Workspace user *</label>
            <select name="user_id" id="provision-user-select" class="comm-hub-input w-full" required>
                <option value="">Select agent…</option>
                @foreach ($agents as $agent)
                    @if (!($agent['provisioned'] ?? false))
                        <option value="{{ $agent['user_id'] }}" data-name="{{ $agent['name'] }}">
                            {{ $agent['name'] }} — {{ $agent['role_label'] }} ({{ $agent['email'] }})
                        </option>
                    @endif
                @endforeach
            </select>
        </div>
        <div>
            <label class="comm-hub-label">Extension number *</label>
            <input type="text" name="extension_num" value="{{ $suggestedExt }}" required
                class="comm-hub-input w-full" pattern="[0-9]{3,6}">
        </div>
        <div>
            <label class="comm-hub-label">SIP password *</label>
            <input type="text" name="sip_password" required minlength="8" class="comm-hub-input w-full"
                autocomplete="new-password" placeholder="Min 8 characters">
        </div>
        <div>
            <label class="comm-hub-label">Caller ID name</label>
            <input type="text" name="caller_id_name" id="provision-caller-name" class="comm-hub-input w-full"
                placeholder="Agent name">
        </div>
        <div>
            <label class="comm-hub-label">Caller ID number (outbound DID)</label>
            <input type="text" name="caller_id_num" class="comm-hub-input w-full" placeholder="+1 555 123 4567"
                title="Required for correct outbound caller ID once Morpheus delivers your DIDs">
        </div>
        <div class="md:col-span-2 flex items-center gap-2">
            <input type="hidden" name="create_morpheus_user" value="1">
            <button type="submit" class="comm-hub-btn">Create phone line</button>
        </div>
    </form>
</div>
@endif

<div class="ghl-card">
    <h3 class="ghl-card-title">Phone agents ({{ count($agents) }})</h3>
    <x-data-table>
        <table class="mt-3 w-full text-sm">
            <thead>
                <tr>
                    <th class="text-left">Agent</th>
                    <th class="text-left">Extension</th>
                    <th class="text-left">Caller ID</th>
                    <th class="text-left">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($agents as $agent)
                    <tr class="border-t border-slate-100">
                        <td class="py-3 pr-2">
                            <div class="font-semibold text-zinc-900">{{ $agent['name'] }}</div>
                            <div class="text-xs text-zinc-500">{{ $agent['role_label'] }} · {{ $agent['email'] }}
                            </div>
                        </td>
                        <td class="py-3 pr-2">
                            @if ($agent['provisioned'])
                                <span class="font-mono font-semibold">{{ $agent['morpheus_extension_num'] }}</span>
                            @else
                                <span class="text-zinc-400">Not provisioned</span>
                            @endif
                        </td>
                        <td class="py-3 pr-2 text-xs">
                            {{ $agent['caller_id_name'] ?? '—' }}
                            @if (!empty($agent['caller_id_num']))
                                <br><span class="text-zinc-500">{{ $agent['caller_id_num'] }}</span>
                            @endif
                        </td>
                        <td class="py-3 pr-2">
                            @if ($agent['provisioned'])
                                <span class="ghl-tag">{{ $agent['extension_status'] ?? 'active' }}</span>
                            @else
                                <span class="ghl-tag ghl-tag-muted">—</span>
                            @endif
                        </td>
                        <td class="py-3 text-right whitespace-nowrap">
                            @if ($agent['provisioned'])
                                <details class="text-left inline-block">
                                    <summary class="comm-hub-link text-xs cursor-pointer">Edit</summary>
                                    <form method="POST"
                                        action="{{ route($routePrefix . 'communications.morpheus.agents.update', ['user' => $agent['user_id']]) }}"
                                        class="mt-2 p-3 border border-slate-200 rounded-lg bg-slate-50 min-w-[16rem]">
                                        @csrf
                                        @method('PATCH')
                                        <label class="comm-hub-label text-xs">New SIP password</label>
                                        <input type="text" name="sip_password" minlength="8"
                                            class="comm-hub-input w-full mb-2 comm-hub-input-sm"
                                            placeholder="Leave blank to keep">
                                        <label class="comm-hub-label text-xs">Caller ID name</label>
                                        <input type="text" name="caller_id_name"
                                            value="{{ $agent['caller_id_name'] }}"
                                            class="comm-hub-input w-full mb-2 comm-hub-input-sm">
                                        <label class="comm-hub-label text-xs">Caller ID number</label>
                                        <input type="text" name="caller_id_num"
                                            value="{{ $agent['caller_id_num'] }}"
                                            class="comm-hub-input w-full mb-2 comm-hub-input-sm">
                                        <label class="comm-hub-label text-xs">Status</label>
                                        <select name="status" class="comm-hub-input w-full mb-2 comm-hub-input-sm">
                                            <option value="active" @selected(($agent['extension_status'] ?? 'active') === 'active')>Active</option>
                                            <option value="disabled" @selected(($agent['extension_status'] ?? '') === 'disabled')>Disabled</option>
                                        </select>
                                        <button type="submit" class="comm-hub-btn comm-hub-btn-sm w-full">Save</button>
                                    </form>
                                </details>
                                <form method="POST"
                                    action="{{ route($routePrefix . 'communications.morpheus.agents.deprovision', ['user' => $agent['user_id']]) }}"
                                    class="inline ml-2"
                                    onsubmit="return confirm('Remove phone line for {{ $agent['name'] }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="comm-hub-link text-xs text-red-600">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="ghl-empty py-8">No workspace users found. Add agents in User
                            Management first.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-data-table>
    <x-communications.list-pagination :pagination="$panelPagination ?? null" class="mt-4" />
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('provision-agent-form');
        const userSelect = document.getElementById('provision-user-select');
        const callerName = document.getElementById('provision-caller-name');
        if (!form || !userSelect) return;

        const actionTemplate = @json(route($routePrefix . 'communications.morpheus.agents.provision', ['user' => '__USER__']));

        userSelect.addEventListener('change', function() {
            const opt = userSelect.options[userSelect.selectedIndex];
            if (opt?.dataset?.name && callerName && !callerName.value) {
                callerName.value = opt.dataset.name;
            }
            if (userSelect.value) {
                form.action = actionTemplate.replace('__USER__', userSelect.value);
            }
        });
    });
</script>
