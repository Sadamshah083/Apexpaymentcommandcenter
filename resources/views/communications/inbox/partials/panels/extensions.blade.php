@php
    $items = $morpheusExtensions ?? [];
    $availablePhoneLines = collect($availablePhoneLines ?? []);
    $defaultLine = $availablePhoneLines->first();
@endphp

@if ($hubAccess['canConfigure'] ?? false)
<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create SIP extension</h3>
    <p class="text-sm text-slate-500 mb-3">
        {{ $availablePhoneLines->count() }} remaining extension/DID pair{{ $availablePhoneLines->count() === 1 ? '' : 's' }} from the billing pool (3rd-party Morpheus API).
    </p>
    <form method="POST" action="{{ route($routePrefix . 'communications.morpheus.extensions.store') }}"
        class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3" id="create-extension-form">
        @csrf
        @if ($availablePhoneLines->isNotEmpty())
            <select name="extension_num" class="comm-hub-input" required data-create-ext-select>
                @foreach ($availablePhoneLines as $line)
                    <option value="{{ $line['extension'] }}" data-did="{{ $line['did'] }}"
                        @selected(($defaultLine['extension'] ?? '') === $line['extension'])>
                        {{ $line['label'] }}
                    </option>
                @endforeach
            </select>
            <input type="text" name="caller_id_num" class="comm-hub-input" readonly
                value="{{ $defaultLine['did'] ?? '' }}" data-create-ext-did placeholder="DID auto-fills">
        @else
            <input type="text" name="extension_num" placeholder="Extension number *" required class="comm-hub-input">
            <input type="text" name="caller_id_num" placeholder="Outbound DID" class="comm-hub-input">
        @endif
        <input type="password" name="password" placeholder="SIP password *" required minlength="8"
            class="comm-hub-input" value="12345678">
        <input type="text" name="caller_id_name" placeholder="Caller ID name" class="comm-hub-input md:col-span-2">
        <button type="submit" class="comm-hub-btn">Create extension</button>
    </form>
    @if ($availablePhoneLines->isNotEmpty())
        <script>
            (() => {
                const form = document.getElementById('create-extension-form');
                const select = form?.querySelector('[data-create-ext-select]');
                const did = form?.querySelector('[data-create-ext-did]');
                if (!select || !did) return;
                select.addEventListener('change', () => {
                    did.value = select.selectedOptions?.[0]?.dataset?.did || '';
                });
            })();
        </script>
    @endif
</div>
@endif

<div class="ghl-card">
    <h3 class="ghl-card-title">Extensions ({{ count($items) }})</h3>
    <x-data-table>
        <table class="mt-3">
            <thead>
                <tr>
                    <th>Extension</th>
                    <th>Caller ID</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $ext)
                    <tr>
                        <td class="font-semibold">{{ $ext['extension_num'] ?? '—' }}</td>
                        <td>{{ $ext['caller_id_name'] ?? '—' }}</td>
                        <td><span class="ghl-tag">{{ $ext['status'] ?? 'active' }}</span></td>
                        <td>
                            <form method="POST"
                                action="{{ route($routePrefix . 'communications.morpheus.extensions.destroy', ['id' => $ext['id'] ?? $ext['extension_num']]) }}"
                                onsubmit="return confirm('Delete extension?')">@csrf @method('DELETE')<button
                                    type="submit" class="comm-hub-link text-xs text-red-600">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="ghl-empty py-6">No extensions.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-data-table>
    <x-communications.list-pagination :pagination="$panelPagination ?? null" class="mt-4" />
</div>
