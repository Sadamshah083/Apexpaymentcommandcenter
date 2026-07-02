@php
    $items = $morpheusLeads ?? [];
    $lists = $morpheusLists ?? [];
@endphp

@if ($hubAccess['canConfigure'] ?? false)
<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create dialer lead</h3>
    <form method="POST" action="{{ route($routePrefix . 'communications.morpheus.leads.store') }}"
        class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
        @csrf
        <input type="text" name="phone_number" placeholder="Phone number *" required class="comm-hub-input">
        <select name="list_id" required class="comm-hub-input">
            <option value="">Select list *</option>
            @foreach ($lists as $list)
                <option value="{{ $list['id'] }}">{{ $list['name'] ?? 'List' }}</option>
            @endforeach
        </select>
        <input type="text" name="first_name" placeholder="First name" class="comm-hub-input">
        <input type="text" name="last_name" placeholder="Last name" class="comm-hub-input">
        <input type="email" name="email" placeholder="Email" class="comm-hub-input">
        <button type="submit" class="comm-hub-btn">Create lead</button>
    </form>
</div>
@endif

<div class="ghl-card">
    <h3 class="ghl-card-title">Dialer leads ({{ count($items) }})</h3>
    <x-data-table>
        <table class="mt-3">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Disposition</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $lead)
                    <tr>
                        <td>{{ trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?: '—' }}</td>
                        <td>{{ $lead['phone_number'] ?? '—' }}</td>
                        <td><span class="ghl-tag">{{ $lead['status'] ?? '—' }}</span></td>
                        <td>{{ $lead['disposition'] ?? '—' }}</td>
                        <td>
                            <form method="POST"
                                action="{{ route($routePrefix . 'communications.morpheus.leads.destroy', ['id' => $lead['id']]) }}"
                                onsubmit="return confirm('Delete lead?')">@csrf @method('DELETE')<button type="submit"
                                    class="comm-hub-link text-xs text-red-600">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="ghl-empty py-6">No leads found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-data-table>
    <x-communications.list-pagination :pagination="$panelPagination ?? null" class="mt-4" />
</div>
