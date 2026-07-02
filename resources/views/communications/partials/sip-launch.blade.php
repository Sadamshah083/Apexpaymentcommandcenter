@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Connecting call')

@section('content')
<div class="ghl-inbox" style="max-width: 32rem; margin: 2rem auto;">
    <div class="comm-hub-card p-6 text-center">
        <h1 class="text-lg font-bold text-zinc-900 mb-2">Opening your softphone</h1>
        <p class="text-sm text-zinc-600 mb-4">
            Morpheus is handing this call to your registered SIP client (Zoiper, Linphone, or Morpheus web phone).
            If nothing happens, use the link below.
        </p>
        <p class="text-xs text-zinc-500 mb-4 break-all font-mono">{{ $sipUrl }}</p>
        <div class="flex flex-col gap-2">
            <a href="{{ $sipUrl }}" id="sip-launch-link" class="comm-hub-btn ghl-dialer-call-btn">Launch call in softphone</a>
            <a href="{{ route($routePrefix.'communications.index', ['panel' => 'dialer']) }}" class="comm-hub-btn comm-hub-btn-secondary">Back to dialer</a>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sipUrl = @json($sipUrl);
    if (sipUrl) {
        window.setTimeout(function () {
            window.location.href = sipUrl;
        }, 150);
    }
});
</script>
@endpush
