@props([
    'steps' => [],
])

<nav class="ch-workflow" aria-label="Outbound call steps" data-comm-workflow>
    @foreach ($steps as $index => $step)
        <div class="ch-workflow__step {{ $step['state'] ?? '' }}" data-workflow-step="{{ $index + 1 }}">
            <span class="ch-workflow__num">{{ $index + 1 }}</span>
            <p class="ch-workflow__title">{{ $step['title'] ?? '' }}</p>
            <p class="ch-workflow__desc">{{ $step['desc'] ?? '' }}</p>
        </div>
    @endforeach
</nav>
