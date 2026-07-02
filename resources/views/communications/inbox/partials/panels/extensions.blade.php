@php $items = $morpheusExtensions ?? []; @endphp

@if ($hubAccess['canConfigure'] ?? false)
<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create SIP extension</h3>
    <form method="POST" action="{{ route($routePrefix . 'communications.morpheus.extensions.store') }}"
        class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
        @csrf
        <input type="text" name="extension_num" placeholder="Extension number *" required class="comm-hub-input">
        <input type="password" name="password" placeholder="SIP password *" required minlength="8"
            class="comm-hub-input">
        <input type="text" name="caller_id_name" placeholder="Caller ID name" class="comm-hub-input">
        <button type="submit" class="comm-hub-btn md:col-span-3">Create extension</button>
    </form>
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
</div>
