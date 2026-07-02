@php
    $compact = $compact ?? false;
    $inputClass = $compact ? 'app-input app-input-sm' : 'app-input';
@endphp

<form method="POST" action="{{ route('portal.leads.setter-status', $lead->id) }}"
    class="setter-status-form space-y-2 {{ $compact ? 'setter-status-form-compact' : 'space-y-3' }}">
    @csrf
    <div>
        @if (!$compact)
            <label for="setter-status-{{ $lead->id }}"
                class="block text-xs font-semibold text-zinc-600 mb-1">Status</label>
        @endif
        <select id="setter-status-{{ $lead->id }}" name="setter_status" required
            class="{{ $inputClass }} {{ $compact ? 'w-full' : 'max-w-xs' }}">
            @foreach ($setterStatuses as $value => $label)
                <option value="{{ $value }}" @selected($lead->setter_status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        @if (!$compact)
            <label for="setter-status-note-{{ $lead->id }}"
                class="block text-xs font-semibold text-zinc-600 mb-1">Note</label>
        @endif
        <textarea id="setter-status-note-{{ $lead->id }}" name="notes" rows="{{ $compact ? 2 : 3 }}"
            class="{{ $inputClass }} w-full" placeholder="What happened on this call or follow-up? (optional)"></textarea>
    </div>
    <button type="submit" class="app-btn app-btn-primary {{ $compact ? 'app-btn-sm w-full' : '' }}">
        Save status
    </button>
</form>
