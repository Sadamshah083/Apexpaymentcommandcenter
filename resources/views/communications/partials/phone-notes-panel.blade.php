@php
    $notesShowUrl = route($routePrefix . 'communications.dialer.notes.show');
    $phoneNoteSaveUrl = route($routePrefix . 'communications.dialer.notes.phone.save');
    $callNoteSaveUrl = route($routePrefix . 'communications.dialer.notes.call.save');
@endphp

<div class="ghl-dialer-notes-panel" data-dialer-notes-panel
    data-notes-show-url="{{ $notesShowUrl }}"
    data-notes-phone-save-url="{{ $phoneNoteSaveUrl }}"
    data-notes-call-save-url="{{ $callNoteSaveUrl }}">
    <button type="button" class="ghl-dialer-notes-toggle" data-dialer-notes-toggle
        aria-expanded="false" aria-controls="ghl-dialer-notes-drawer" title="Notes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="16" y1="13" x2="8" y2="13" />
            <line x1="16" y1="17" x2="8" y2="17" />
        </svg>
        <span>Notes</span>
        <span class="ghl-dialer-notes-indicator hidden" data-dialer-notes-indicator aria-hidden="true"></span>
    </button>
    <div class="ghl-dialer-notes-drawer hidden" id="ghl-dialer-notes-drawer" data-dialer-notes-drawer>
        <div class="ghl-dialer-notes-drawer__head">
            <p class="ghl-dialer-notes-drawer__title">Notes</p>
            <p class="ghl-dialer-notes-drawer__phone" data-dialer-notes-phone-label>—</p>
        </div>
        <textarea class="ghl-dialer-notes-input" data-dialer-notes-input rows="5"
            placeholder="Add notes for this number or call…" maxlength="5000"></textarea>
        <div class="ghl-dialer-notes-drawer__actions">
            <span class="ghl-dialer-notes-status" data-dialer-notes-status aria-live="polite"></span>
            <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-dialer-notes-save>Save</button>
        </div>
    </div>
</div>
