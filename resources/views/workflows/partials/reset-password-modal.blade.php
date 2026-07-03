<div id="um-reset-password-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="um-reset-password-title" aria-modal="true">
    <div class="member-confirm-backdrop" data-um-reset-password-dismiss></div>
    <div class="member-confirm-panel um-add-member-panel" role="document">
        <div class="um-add-member-panel-header">
            <div>
                <h2 id="um-reset-password-title" class="member-confirm-title">Reset password</h2>
                <p id="um-reset-password-desc" class="um-panel-desc um-add-member-desc">
                    Set a new password for this team member.
                </p>
            </div>
            <button type="button" class="app-modal-close" data-um-reset-password-dismiss aria-label="Close">&times;</button>
        </div>

        <form id="um-reset-password-form" method="POST" action="#" data-member-action="reset-password"
            data-member-name="" class="um-form-stack">
            @csrf
            <div class="um-field">
                <label class="um-label" for="um-reset-password-new">New password</label>
                <input id="um-reset-password-new" type="password" name="password" required minlength="6"
                    placeholder="Min. 6 characters" class="um-input" autocomplete="new-password">
            </div>
            <div class="um-field">
                <label class="um-label" for="um-reset-password-confirm">Confirm password</label>
                <input id="um-reset-password-confirm" type="password" name="password_confirmation" required
                    minlength="6" placeholder="Repeat password" class="um-input" autocomplete="new-password">
            </div>

            <div class="member-confirm-actions um-add-member-actions">
                <button type="button" class="member-confirm-cancel" data-um-reset-password-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit um-btn um-btn-primary">Update password</button>
            </div>
        </form>
    </div>
</div>
