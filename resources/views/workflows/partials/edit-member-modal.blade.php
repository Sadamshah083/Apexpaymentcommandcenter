@php
    use App\Support\SalesOps;

    $assignableRoles = SalesOps::assignableMemberRoles();
    $campaigns = collect($campaigns ?? []);
    $setterTeamLeads = collect($setterTeamLeads ?? []);
    $closerTeamLeads = collect($closerTeamLeads ?? []);
    $campaignNames = collect($campaignNames ?? []);
    $teamLeadCampaignIds = collect($teamLeadCampaignIds ?? []);

    $mapLeads = static function ($leads) use ($teamLeadCampaignIds, $campaignNames) {
        return collect($leads)->map(function ($lead) use ($teamLeadCampaignIds, $campaignNames) {
            $campaignId = (int) ($teamLeadCampaignIds->get((int) $lead->id) ?? 0);

            return [
                'id' => (int) $lead->id,
                'name' => $lead->name,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignId > 0 ? ($campaignNames->get($campaignId) ?: null) : null,
            ];
        })->values()->all();
    };

    $setterLeadsJson = $mapLeads($setterTeamLeads);
    $closerLeadsJson = $mapLeads($closerTeamLeads);
@endphp

<div id="um-edit-member-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="um-edit-member-title" aria-modal="true"
    data-setter-leads='@json($setterLeadsJson)'
    data-closer-leads='@json($closerLeadsJson)'>
    <div class="member-confirm-backdrop" data-um-edit-member-dismiss></div>
    <div class="member-confirm-panel um-add-member-panel" role="document">
        <div class="um-add-member-panel-header">
            <div>
                <h2 id="um-edit-member-title" class="member-confirm-title">Edit account</h2>
                <p id="um-edit-member-desc" class="um-panel-desc um-add-member-desc">
                    Update username, role, campaign / team lead assignment, email, and password.
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
                    <label class="um-label" for="um-edit-member-role">Role</label>
                    <select id="um-edit-member-role" name="role" class="um-input um-select" data-edit-member-role>
                        @foreach ($assignableRoles as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="um-field" data-edit-campaign-field hidden>
                    <label class="um-label" for="um-edit-member-campaign">Campaign (team lead only)</label>
                    <select id="um-edit-member-campaign" name="campaign_id" class="um-input um-select"
                        data-edit-member-campaign>
                        <option value="">Unassigned</option>
                        @foreach ($campaigns as $campaign)
                            <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                        @endforeach
                    </select>
                    <p class="um-field-hint">Assign B2B Fronter or B2B Closer. Only this lead’s agents inherit it.</p>
                </div>
                <div class="um-field" data-edit-team-lead-field hidden>
                    <label class="um-label" for="um-edit-member-team-lead">Team lead (agent only)</label>
                    <select id="um-edit-member-team-lead" name="team_lead_user_id" class="um-input um-select"
                        data-edit-member-team-lead>
                        <option value="">Unassigned</option>
                    </select>
                    <p class="um-field-hint">Agent joins that team lead only — not other teams.</p>
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
