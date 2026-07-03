<div id="um-edit-member-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="um-edit-member-title" aria-modal="true">
    <div class="member-confirm-backdrop" data-um-edit-member-dismiss></div>
    <div class="member-confirm-panel um-add-member-panel" role="document">
        <div class="um-add-member-panel-header">
            <div>
                <h2 id="um-edit-member-title" class="member-confirm-title">Edit account</h2>
                <p id="um-edit-member-desc" class="um-panel-desc um-add-member-desc">
                    Update username, email, and password for this team member.
                </p>
            </div>
            <button type="button" class="app-modal-close" data-um-edit-member-dismiss aria-label="Close">&times;</button>
        </div>

        <form id="um-edit-member-form" method="POST" action="#" data-member-action="update"
            data-member-name="" class="um-form-stack">
            @csrf
            @method('PATCH')
            <div class="um-form-grid">
                <div class="um-field">
                    <label class="um-label" for="um-edit-member-username">Username</label>
                    <input id="um-edit-member-username" type="text" name="username" required maxlength="255"
                        class="um-input" autocomplete="off">
                </div>
                <div class="um-field">
                    <label class="um-label" for="um-edit-member-email">Email</label>
                    <input id="um-edit-member-email" type="email" name="email" required maxlength="255"
                        class="um-input" autocomplete="email">
                </div>
                <div class="um-field">
                    <label class="um-label" for="um-edit-member-password">New password</label>
                    <input id="um-edit-member-password" type="password" name="password" minlength="6"
                        placeholder="Leave blank to keep current" class="um-input" autocomplete="new-password">
                </div>
                <div class="um-field">
                    <label class="um-label" for="um-edit-member-password-confirm">Confirm password</label>
                    <input id="um-edit-member-password-confirm" type="password" name="password_confirmation"
                        minlength="6" placeholder="Only if changing password" class="um-input" autocomplete="new-password">
                </div>
            </div>

            <div class="member-confirm-actions um-add-member-actions">
                <button type="button" class="member-confirm-cancel" data-um-edit-member-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit um-btn um-btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</div>
