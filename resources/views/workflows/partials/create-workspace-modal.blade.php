<div id="um-create-workspace-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="um-create-workspace-title" aria-modal="true">
    <div class="member-confirm-backdrop" data-um-create-workspace-dismiss></div>
    <div class="member-confirm-panel um-add-member-panel" role="document">
        <div class="um-add-member-panel-header">
            <div>
                <h2 id="um-create-workspace-title" class="member-confirm-title">Create workspace</h2>
                <p class="um-panel-desc um-add-member-desc">
                    Add an isolated context for another team or client.
                </p>
            </div>
            <button type="button" class="app-modal-close" data-um-create-workspace-dismiss aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="{{ route('admin.workspaces.store') }}" class="um-form-stack" data-workspace-create-form>
            @csrf
            <div class="um-field">
                <label class="um-label" for="create-workspace-name">Workspace name</label>
                <input id="create-workspace-name" type="text" name="name" required placeholder="Workspace name"
                    value="{{ old('name') }}" class="um-input" autocomplete="off">
            </div>

            <div class="member-confirm-actions um-add-member-actions">
                <button type="button" class="member-confirm-cancel" data-um-create-workspace-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit um-btn um-btn-primary">Create workspace</button>
            </div>
        </form>
    </div>
</div>
