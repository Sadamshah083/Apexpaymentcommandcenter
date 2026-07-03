@if (!empty($focus))
    <div class="dash-focus-banner" id="dash-focus-banner">
        <div class="dash-focus-banner-body">
            <div>
                <p class="dash-focus-label">Filtered view</p>
                <h2 class="dash-focus-title">{{ $focus['title'] }}</h2>
                <p class="dash-focus-desc">{{ $focus['description'] }}</p>
            </div>
            <a href="{{ url()->current() }}" class="dash-focus-clear">Clear filter</a>
        </div>
    </div>
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var section = document.getElementById('portal-leads-section');
                if (section) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        </script>
    @endpush
@endif
