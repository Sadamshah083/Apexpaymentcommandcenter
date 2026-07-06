<div class="ch-empty-state ghl-detail-empty">
    <div class="ch-empty-state__icon ghl-detail-empty-icon" aria-hidden="true">{{ $icon ?? '📋' }}</div>
    <h2 class="ch-empty-state__title app-page-title text-lg">{{ $title ?? 'Nothing selected' }}</h2>
    <p class="ch-empty-state__message app-page-subtitle max-w-md">{{ $message ?? 'Choose an item from the list.' }}</p>
    @if (trim($slot ?? '') !== '')
        <div class="ch-empty-state__actions mt-4">{{ $slot }}</div>
    @endif
</div>
