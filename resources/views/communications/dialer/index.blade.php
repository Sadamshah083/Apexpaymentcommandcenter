@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Dialer')

@section('content')
    <div class="ghl-hub">
        @include('communications.partials.hub-tabs', ['mode' => 'dialer', 'routePrefix' => $routePrefix])

        @if ($warning ?? null)
            <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
        @endif

        @if ($error ?? null)
            <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
        @endif

        <div class="ghl-dialer-layout">
            <aside class="ghl-dialer-side">
                <h2 class="text-sm font-bold text-zinc-900">Recent numbers</h2>
                <p class="text-xs text-zinc-500 mt-1 mb-3">Numbers from recent Morpheus activity</p>
                <div class="ghl-dialer-recent">
                    @forelse($recentNumbers ?? [] as $number)
                        <button type="button" class="ghl-dialer-recent-btn"
                            data-dial-number="{{ $number }}">{{ $number }}</button>
                    @empty
                        <p class="ghl-empty py-4">No recent numbers yet.</p>
                    @endforelse
                </div>
            </aside>

            <section class="ghl-dialer-panel">
                @include('communications.partials.global-line-picker', [
                    'routePrefix' => $routePrefix,
                    'morpheusExtensions' => $morpheusExtensions ?? [],
                    'phoneUsers' => $phoneUsers ?? [],
                    'defaultCallerId' => $defaultCallerId ?? null,
                    'placement' => 'panel',
                ])
                @include('communications.partials.dialer-form', [
                    'routePrefix' => $routePrefix,
                    'callerSelectId' => 'dial-caller-id',
                    'numberInputId' => 'dial-number',
                    'dialBtnId' => 'morpheus-dial-btn',
                    'backspaceId' => 'dial-backspace',
                    'keypadRootId' => 'dial-keypad',
                    'prefillNumber' => $prefillNumber ?? '',
                    'phoneUsers' => $phoneUsers ?? [],
                    'morpheusExtensions' => $morpheusExtensions ?? [],
                    'defaultCallerId' => $defaultCallerId ?? null,
                    'clickToCall' => $clickToCall ?? null,
                ])
            </section>
        </div>
    </div>
@endsection
