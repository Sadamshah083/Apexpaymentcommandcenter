@props(['activity'])

@php
    use App\Support\SalesOps;

    $typeLabels = config('sales_ops.activity_types', []);
@endphp

<li class="lead-timeline-item {{ $activity->isStatusChange() ? 'lead-timeline-item-status' : '' }}">
    <div class="lead-timeline-dot" aria-hidden="true"></div>
    <div class="lead-timeline-body">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="font-semibold text-zinc-900">{{ $activity->user?->name ?? 'System' }}</span>
            <span class="text-xs text-zinc-400">
                {{ $activity->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
            </span>
        </div>

        @if ($activity->isStatusChange())
            @php
                $role = $activity->statusRole();
                $from = $activity->statusFrom();
                $to = $activity->statusTo() ?? $activity->outcome;
                $label = $role === 'closer' ? SalesOps::closerStatusLabel($to) : SalesOps::setterStatusLabel($to);
                $fromLabel = $from
                    ? ($role === 'closer'
                        ? SalesOps::closerStatusLabel($from)
                        : SalesOps::setterStatusLabel($from))
                    : null;
            @endphp
            <p class="text-sm text-zinc-700 mt-1">
                @if ($role === 'closer')
                    Updated closer status to
                @else
                    Updated setter status to
                @endif
                <span class="inline-flex items-center font-bold text-indigo-700">{{ $label }}</span>
            </p>
            @if ($fromLabel)
                <p class="text-xs text-zinc-500 mt-0.5">Previously: {{ $fromLabel }}</p>
            @endif
        @else
            <p class="text-sm text-zinc-700 mt-1">
                {{ $typeLabels[$activity->type] ?? ucfirst(str_replace('_', ' ', $activity->type)) }}
                @if ($activity->outcome)
                    <span class="text-zinc-500">· {{ $activity->outcome }}</span>
                @endif
            </p>
        @endif

        @if ($activity->notes)
            <p
                class="text-sm text-zinc-600 mt-2 whitespace-pre-wrap bg-zinc-50 rounded-lg px-3 py-2 border border-zinc-100">
                {{ $activity->notes }}</p>
        @endif
    </div>
</li>
