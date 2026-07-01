@php
    $tags = $tags ?? collect();
    $list = $list ?? null;
    $compact = $compact ?? false;
@endphp

@if($list)
    <div class="{{ $compact ? 'text-[10px]' : 'text-xs' }} text-zinc-400 mt-0.5">
        List: <span class="font-medium text-zinc-600">{{ $list->name }}</span>
    </div>
@endif

@if($tags->isNotEmpty())
    <div class="flex flex-wrap gap-1 {{ $list ? 'mt-1' : 'mt-0.5' }}">
        @foreach($tags as $tag)
            <span
                class="{{ $compact ? 'text-[10px]' : 'text-xs' }} font-semibold px-1.5 py-0.5 rounded-full bg-zinc-100 text-zinc-600"
                style="border-left: 2px solid {{ $tag->color }}"
            >{{ $tag->name }}</span>
        @endforeach
    </div>
@endif
