<div id="member-confirm-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="member-confirm-title" aria-modal="true">
    <div class="member-confirm-backdrop" data-member-confirm-dismiss></div>
    <div class="member-confirm-panel" role="document">
        <div id="member-confirm-icon" class="member-confirm-icon member-confirm-icon-warning" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
            </svg>
        </div>
        <h2 id="member-confirm-title" class="member-confirm-title">Confirm action</h2>
        <p id="member-confirm-message" class="member-confirm-message">Are you sure?</p>
        <div class="member-confirm-actions">
            <button type="button" class="member-confirm-cancel" data-member-confirm-dismiss>Cancel</button>
            <button type="button" id="member-confirm-submit" class="member-confirm-submit">Confirm</button>
        </div>
    </div>
</div>
