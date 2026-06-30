@props(['status'])

@php
    $gemini = $status['gemini'] ?? [];
    $openrouter = $status['openrouter'] ?? [];
    $geminiState = $gemini['state'] ?? 'not_configured';
    $orState = $openrouter['state'] ?? 'not_configured';

    $geminiBadge = match ($geminiState) {
        'ready' => 'app-badge app-badge-success',
        'depleted' => 'app-badge app-badge-danger',
        'invalid' => 'app-badge app-badge-danger',
        'not_configured' => 'app-badge app-badge-muted',
        default => 'app-badge app-badge-warning',
    };
@endphp

<div class="app-card app-card-padded space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h2 class="app-section-title">AI enrichment providers</h2>
            <p class="app-section-desc">
                Pipeline uses <strong>{{ $status['pipeline_model'] ?? 'gemini-2.0-flash' }}</strong>
                (max {{ number_format($status['pipeline_max_tokens'] ?? 2048) }} output tokens) + free DuckDuckGo context.
            </p>
        </div>
        @if(request()->routeIs('admin.*'))
            <a href="{{ request()->fullUrlWithQuery(['refresh_enrichment' => 1]) }}" class="app-btn app-btn-secondary app-btn-sm whitespace-nowrap">
                Refresh status
            </a>
        @endif
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        <div class="rounded-lg border border-zinc-200 p-4 space-y-2">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold text-zinc-800">Gemini</span>
                <span class="{{ $geminiBadge }}">{{ $gemini['label'] ?? 'Unknown' }}</span>
            </div>
            @if($geminiState === 'not_configured')
                <p class="text-zinc-500 text-xs">GEMINI_API_KEY not set.</p>
            @else
                @if($gemini['checked_at'] ?? null)
                    <p class="text-xs text-zinc-400">Checked {{ \Carbon\Carbon::parse($gemini['checked_at'])->diffForHumans() }}</p>
                @endif
                @if($gemini['message'] ?? null)
                    <p class="text-xs text-zinc-600">{{ $gemini['message'] }}</p>
                @endif
                @if($geminiState === 'depleted')
                    <p class="text-xs text-rose-700 font-medium">
                        Top up at <a href="https://aistudio.google.com/app/billing" class="app-link" target="_blank" rel="noopener">AI Studio Billing</a>
                        or use OpenRouter fallback.
                    </p>
                @endif
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 space-y-2">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold text-zinc-800">OpenRouter (fallback)</span>
                <span class="app-badge {{ $orState === 'ready' ? 'app-badge-success' : 'app-badge-muted' }}">
                    {{ $openrouter['label'] ?? 'Unknown' }}
                </span>
            </div>
            @if($orState === 'not_configured')
                <p class="text-zinc-500 text-xs">OPENROUTER_API_KEY not set — no fallback if Gemini fails.</p>
            @elseif($openrouter['balance'] !== null)
                <p class="text-xs text-zinc-600">Balance: {{ $openrouter['balance'] }}</p>
            @else
                <p class="text-xs text-zinc-500">{{ $openrouter['message'] ?? '' }}</p>
            @endif
        </div>
    </div>

    @if(($gemini['last_error'] ?? null) && $geminiState !== 'ready')
        <div class="app-alert app-alert-warning">
            <p class="app-alert-title">Last enrichment error</p>
            <p class="app-alert-desc text-xs font-mono break-all">{{ $gemini['last_error'] }}</p>
        </div>
    @endif

    @if(!($status['configured'] ?? false))
        <div class="app-alert app-alert-danger">
            <p class="app-alert-desc">{{ $status['message'] ?? 'Configure GEMINI_API_KEY or OPENROUTER_API_KEY.' }}</p>
        </div>
    @endif
</div>
