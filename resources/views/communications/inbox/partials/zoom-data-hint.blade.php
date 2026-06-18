@if(!empty($connectionDiagnostics['messages']) || !empty($warnings))
    <div class="comm-hub-alert comm-hub-alert-warning mb-4 text-left">
        <p class="font-semibold text-sm mb-2">Zoom is connected, but call data is not available yet.</p>
        <ul class="text-sm space-y-1.5 list-disc pl-5">
            @foreach($connectionDiagnostics['messages'] ?? [] as $hint)
                <li>{{ $hint }}</li>
            @endforeach
            @foreach($warnings ?? [] as $warning)
                @if(!in_array($warning, $connectionDiagnostics['messages'] ?? [], true))
                    <li>{{ $warning }}</li>
                @endif
            @endforeach
        </ul>
        <p class="text-xs mt-3 opacity-90">
            Open <a href="{{ route($routePrefix.'communications.index', ['channel' => 'inbox', 'panel' => 'settings']) }}" class="underline font-semibold">Settings → Refresh data</a>
            after updating your Zoom app scopes or enabling Zoom Phone.
        </p>
    </div>
@endif
