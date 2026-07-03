@php
    use App\Support\SalesOps;
@endphp

<div id="um-add-member-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="um-add-member-title" aria-modal="true">
    <div class="member-confirm-backdrop" data-um-add-member-dismiss></div>
    <div class="member-confirm-panel um-add-member-panel" role="document">
        <div class="um-add-member-panel-header">
            <div>
                <h2 id="um-add-member-title" class="member-confirm-title">Add team account</h2>
                <p class="um-panel-desc um-add-member-desc">
                    Admins and managers use the <strong>admin portal</strong>. Setters and closers use the
                    <strong>agent portal</strong> with their username.
                </p>
            </div>
            <button type="button" class="app-modal-close" data-um-add-member-dismiss aria-label="Close">&times;</button>
        </div>

        @if ($errors->any())
            <div class="um-alert um-alert-error" role="alert">
                <ul class="um-alert-list">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.workspaces.members.store', $activeWorkspace->id) }}"
            class="um-add-member-form" data-workspace-create-member>
            @csrf
            <div class="um-form-grid">
                <div class="um-field">
                    <label class="um-label" for="create-username">Username</label>
                    <input id="create-username" type="text" name="username" required placeholder="e.g. setter_ag_k8z"
                        value="{{ old('username') }}" class="um-input" autocomplete="off">
                </div>
                <div class="um-field">
                    <label class="um-label" for="create-role">Role</label>
                    <select id="create-role" name="role" class="um-input um-select" data-create-member-role>
                        @foreach (SalesOps::creatableAgentRoles() as $value => $label)
                            <option value="{{ $value }}"
                                {{ old('role', 'appointment_setter') === $value ? 'selected' : '' }}>
                                {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="um-field">
                    <label class="um-label" for="create-password">Password</label>
                    <input id="create-password" type="password" name="password" required minlength="6"
                        placeholder="Min. 6 characters" class="um-input" autocomplete="new-password">
                </div>
                <div class="um-field">
                    <label class="um-label" for="create-password-confirm">Confirm password</label>
                    <input id="create-password-confirm" type="password" name="password_confirmation" required
                        minlength="6" placeholder="Repeat password" class="um-input" autocomplete="new-password">
                </div>
            </div>

            <div class="create-member-modules um-module-panel hidden" data-create-member-modules>
                @include('workflows.partials.member-module-access-fields', [
                    'prefix' => 'create',
                    'activeWorkspace' => $activeWorkspace,
                ])
            </div>

            <div class="member-confirm-actions um-add-member-actions">
                <button type="button" class="member-confirm-cancel" data-um-add-member-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit um-btn um-btn-primary">Create account</button>
            </div>
        </form>
    </div>
</div>
