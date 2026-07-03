<div id="um-portal-info-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="um-portal-info-title" aria-modal="true">
    <div class="member-confirm-backdrop" data-um-portal-info-dismiss></div>
    <div class="member-confirm-panel um-add-member-panel" role="document">
        <div class="um-add-member-panel-header">
            <div>
                <h2 id="um-portal-info-title" class="member-confirm-title">Sign-in URLs</h2>
                <p class="um-panel-desc um-add-member-desc">
                    Share these links with team members for admin and agent portal access.
                </p>
            </div>
            <button type="button" class="app-modal-close" data-um-portal-info-dismiss aria-label="Close">&times;</button>
        </div>

        <div class="um-portal-links um-portal-links-modal">
            <div class="um-portal-link">
                <span class="um-portal-link-label">Admin portal</span>
                <code class="um-portal-link-url">{{ $adminPortalUrl }}</code>
                <span class="um-portal-link-hint">Super Admin, Admin, Manager</span>
            </div>
            <div class="um-portal-link">
                <span class="um-portal-link-label">Agent portal</span>
                <code class="um-portal-link-url">{{ $agentPortalUrl }}</code>
                <span class="um-portal-link-hint">Setters &amp; closers (use <strong>username</strong>, not email)</span>
            </div>
        </div>

        <div class="member-confirm-actions um-add-member-actions">
            <button type="button" class="member-confirm-cancel um-btn um-btn-primary" data-um-portal-info-dismiss>Close</button>
        </div>
    </div>
</div>
