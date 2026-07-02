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
    $orBadge = match ($orState) {
        'ready' => 'app-badge app-badge-success',
        'depleted', 'invalid' => 'app-badge app-badge-danger',
        'not_configured' => 'app-badge app-badge-muted',
        default => 'app-badge app-badge-warning',
    };
@endphp

<div class="app-card app-card-padded">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-4">
        <div class="min-w-0">
            <h2 class="app-section-title">AI enrichment providers</h2>
            <p class="app-section-desc break-words">
                Pipeline model: <strong>{{ $status['pipeline_model'] ?? 'gemini-2.5-flash' }}</strong>
                ┬╖ max {{ number_format($status['pipeline_max_tokens'] ?? 2048) }} tokens
                ┬╖ DuckDuckGo context
            </p>
        </div>
        @if(request()->routeIs('admin.*'))
            <a href="{{ request()->fullUrlWithQuery(['refresh_enrichment' => 1]) }}" class="app-btn app-btn-secondary app-btn-sm shrink-0">
                Refresh status
            </a>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 flex flex-col gap-2 min-h-[120px]">
            <div class="flex items-center justify-between gap-3">
                <span class="font-semibold text-zinc-900">Gemini</span>
                <span class="{{ $geminiBadge }} shrink-0">{{ $gemini['label'] ?? 'Unknown' }}</span>
            </div>
            @if($geminiState === 'not_configured')
                <p class="text-xs text-zinc-500">Set <code class="text-[11px]">GEMINI_API_KEY</code> in .env</p>
            @else
                @if($gemini['checked_at'] ?? null)
                    <p class="text-xs text-zinc-400">Checked {{ \Carbon\Carbon::parse($gemini['checked_at'])->diffForHumans() }}</p>
                @endif
                @if($gemini['message'] ?? null)
                    <p class="text-xs text-zinc-600 leading-relaxed break-words">{{ $gemini['message'] }}</p>
                @endif
                @if($geminiState === 'depleted')
                    <p class="text-xs text-rose-700 font-medium">
                        <a href="https://aistudio.google.com/app/billing" class="app-link" target="_blank" rel="noopener">Top up AI Studio</a>
                        or use OpenRouter fallback.
                    </p>
                @endif
            @endif
        </div>

        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 flex flex-col gap-2 min-h-[120px]">
            <div class="flex items-center justify-between gap-3">
                <span class="font-semibold text-zinc-900">OpenRouter (fallback)</span>
                <span class="{{ $orBadge }} shrink-0">{{ $openrouter['label'] ?? 'Unknown' }}</span>
            </div>
            @if($orState === 'not_configured')
                <p class="text-xs text-zinc-500">Set <code class="text-[11px]">OPENROUTER_API_KEY</code> for fallback when Gemini fails.</p>
            @elseif($openrouter['balance'] !== null)
                <p class="text-xs text-zinc-600">Balance: {{ $openrouter['balance'] }}</p>
            @else
                <p class="text-xs text-zinc-500 leading-relaxed break-words">{{ $openrouter['message'] ?? 'Balance could not be fetched.' }}</p>
            @endif
        </div>
    </div>

    @if(($gemini['last_error'] ?? null) && $geminiState !== 'ready')
        <div class="app-alert app-alert-warning mt-4">
            <p class="app-alert-title">Last enrichment error</p>
            <p class="app-alert-desc text-xs font-mono break-all">{{ $gemini['last_error'] }}</p>
        </div>
    @endif

    @if(!($status['configured'] ?? false))
        <div class="app-alert app-alert-danger mt-4">
            <p class="app-alert-desc">{{ $status['message'] ?? 'Configure GEMINI_API_KEY or OPENROUTER_API_KEY.' }}</p>
        </div>
    @endif
</div>
