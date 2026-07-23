@php
    $dispositions = config('integrations.communications.dispositions', [
        'Answering Machine',
        'Call Back',
        'Corporate Business',
        'Owner Not Available',
        'Wrong Number/Business',
        'Owner Hung Up',
        'Follow Up',
        'Not Interested',
        'Requested Appointment',
        'No Answer',
        'Gatekeeper',
        'Dead Call',
    ]);
    $dispositionUrl = url((request()->is('admin*') ? '/admin' : '/portal') . '/communications/dialer/disposition');
    $nextCallDelaySec = (int) config('integrations.communications.next_call_delay_sec', 6);
@endphp

<div class="ch-call-summary hidden" data-call-summary-modal aria-hidden="true" role="dialog" aria-modal="true"
    aria-labelledby="ch-call-summary-title" data-disposition-url="{{ $dispositionUrl }}"
    data-next-call-delay-sec="{{ $nextCallDelaySec }}">
    <div class="ch-call-summary__backdrop" data-call-summary-close></div>
    <div class="ch-call-summary__card">
        <header class="ch-call-summary__header">
            <div class="ch-call-summary__title-wrap">
                <span class="ch-call-summary__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                </span>
                <div>
                    <h2 id="ch-call-summary-title" class="ch-call-summary__title">Call Summary</h2>
                    <p class="ch-call-summary__lead" data-call-summary-lead></p>
                </div>
            </div>
        </header>

        <div class="ch-call-summary__scroll">
            <div class="ch-call-summary__meta-row">
                <div class="ch-call-summary__status">
                    <span class="ch-call-summary__ended">Call Ended</span>
                    <span class="ch-call-summary__badge" data-call-summary-result>No-answer</span>
                </div>
                <div class="ch-call-summary__duration" data-call-summary-duration>00 Min 00 Sec</div>
            </div>

            <section class="ch-call-summary__dispositions">
                <div class="ch-call-summary__section-head">
                    <h3>Disposition <span class="ch-call-summary__required">required</span></h3>
                    <p class="ch-call-summary__hint">Single-click to select — double-click (or double-tap) a disposition to save &amp; close.</p>
                </div>
                <div class="ch-call-summary__grid" data-call-summary-dispositions>
                    @foreach ($dispositions as $label)
                        @php $tone = \App\Support\DispositionTone::for($label); @endphp
                        <button type="button"
                            class="ch-call-summary__dispo-btn ch-call-summary__dispo-btn--{{ $tone }}"
                            data-disposition-value="{{ $label }}"
                            data-disposition-tone="{{ $tone }}"
                            title="Double-click / double-tap to save &amp; close">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="ch-call-summary__fields">
                    <div class="ch-call-summary__field">
                        <label class="ch-call-summary__field-label" for="ch-call-summary-custom-disposition">
                            Your disposition
                        </label>
                        <input type="text" id="ch-call-summary-custom-disposition"
                            name="custom_disposition"
                            class="ch-call-summary__input"
                            data-call-summary-custom-disposition
                            maxlength="120"
                            placeholder="Write your own disposition…"
                            autocomplete="off">
                    </div>
                    <div class="ch-call-summary__field">
                        <label class="ch-call-summary__field-label" for="ch-call-summary-note">
                            Comment
                        </label>
                        <textarea id="ch-call-summary-note" name="call_comment"
                            class="ch-call-summary__notes" data-call-summary-note
                            rows="3" maxlength="5000" placeholder="Add a comment for this call…"></textarea>
                    </div>
                </div>
            </section>
        </div>

        <footer class="ch-call-summary__footer">
            <button type="button" class="ch-call-summary__redial" data-call-summary-redial>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
                Redial
            </button>
            <button type="button" class="ch-call-summary__next" data-call-summary-next>
                <span data-call-summary-next-label>Save</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
            <button type="button" class="ch-call-summary__pause" data-call-summary-pause title="Pause auto dial" aria-label="Pause auto dial">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/>
                </svg>
            </button>
        </footer>
    </div>
</div>
