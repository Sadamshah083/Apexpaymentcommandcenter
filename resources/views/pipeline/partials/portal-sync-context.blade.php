@if (! empty($portalView))
    <div id="portal-sync-context" class="hidden" aria-hidden="true"
        data-portal-live-url="{{ route('portal.dashboard.live') }}"
        data-portal-view="{{ $portalView }}"
        data-portal-page="{{ $leads instanceof \Illuminate\Contracts\Pagination\Paginator ? $leads->currentPage() : 1 }}"
        @if (filled(request('search'))) data-portal-search="{{ request('search') }}" @endif
        @if (filled(request('phase'))) data-portal-phase="{{ request('phase') }}" @endif
        @if (filled(request('setter'))) data-portal-setter="{{ request('setter') }}" @endif
        @if (filled(request('closer'))) data-portal-closer="{{ request('closer') }}" @endif
        @if (filled(request('focus'))) data-portal-focus="{{ request('focus') }}" @endif
        @if (filled(request('tier'))) data-portal-tier="{{ request('tier') }}" @endif
        @if (filled(request('status'))) data-portal-status="{{ request('status') }}" @endif
        @if (filled(request('member'))) data-portal-member="{{ request('member') }}" @endif
    ></div>
@endif
