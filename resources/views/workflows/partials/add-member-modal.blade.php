@php
    use App\Support\SalesOps;

    $creatableRoles = SalesOps::creatableAgentRoles();
    if (! auth()->user()->isPlatformSuperAdmin()) {
        $creatableRoles = collect($creatableRoles)->except(['admin'])->all();
    }

    $campaigns = collect($campaigns ?? []);
    $setterTeamLeads = collect($setterTeamLeads ?? []);
    $closerTeamLeads = collect($closerTeamLeads ?? []);
    $campaignNames = collect($campaignNames ?? []);
    $teamLeadCampaignIds = collect($teamLeadCampaignIds ?? []);
    $availablePhoneLines = collect($availablePhoneLines ?? [])->values();
    $suggestedExtension = $suggestedExtension ?? ($availablePhoneLines->first()['suggested_extension'] ?? $availablePhoneLines->first()['extension'] ?? '1021');
    $campaignsCreateUrl = route('admin.campaigns.index');
    $emailDomain = 'apexonepayments.com';
    $defaultPassword = '123456';

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
@endphp

<div id="um-add-member-modal" class="member-confirm-modal" hidden aria-hidden="true" role="dialog"
    aria-labelledby="um-add-member-title" aria-modal="true"
    data-setter-leads='@json($mapLeads($setterTeamLeads))'
    data-closer-leads='@json($mapLeads($closerTeamLeads))'
    data-email-domain="{{ $emailDomain }}"
    data-phone-lines='@json($availablePhoneLines)'>
    <div class="member-confirm-backdrop" data-um-add-member-dismiss></div>
    <div class="member-confirm-panel um-add-member-panel" role="document">
        <div class="um-add-member-panel-header">
            <div>
                <h2 id="um-add-member-title" class="member-confirm-title">Add team account</h2>
                <p class="um-panel-desc um-add-member-desc">
                    Create an account with email <code>{{ '@'.$emailDomain }}</code>, password <code>{{ $defaultPassword }}</code>, and an available extension/DID.
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
            <div class="um-add-member-form-body">
                <div class="um-form-grid um-add-member-form-grid">
                    <div class="um-field">
                        <label class="um-label" for="create-username">Username</label>
                        <input id="create-username" type="text" name="username" required placeholder="e.g. Ryan"
                            value="{{ old('username') }}" class="um-input" autocomplete="off" data-create-member-username>
                    </div>
                    <div class="um-field">
                        <label class="um-label" for="create-email">Email</label>
                        <div class="um-email-compose">
                            <input id="create-email-local" type="text" required
                                placeholder="agentname"
                                value="{{ old('email') ? \Illuminate\Support\Str::before(old('email'), '@') : '' }}"
                                class="um-input" autocomplete="off" data-create-member-email-local>
                            <span class="um-email-compose__domain">{{ '@'.$emailDomain }}</span>
                        </div>
                        <input type="hidden" id="create-email" name="email" value="{{ old('email') }}" data-create-member-email>
                        <p class="um-field-hint">Always uses <strong>{{ '@'.$emailDomain }}</strong> — never apexpayments.</p>
                    </div>
                    <div class="um-field">
                        <label class="um-label" for="create-role">Role</label>
                        <select id="create-role" name="role" class="um-input um-select js-pretty-select" data-pretty-select data-create-member-role>
                            @foreach ($creatableRoles as $value => $label)
                                <option value="{{ $value }}"
                                    {{ old('role', 'appointment_setter') === $value ? 'selected' : '' }}>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="um-field" data-create-campaign-field hidden>
                        <label class="um-label" for="create-campaign">Select campaign</label>
                        <select id="create-campaign" name="campaign_id" class="um-input um-select js-pretty-select"
                            data-pretty-select data-create-member-campaign>
                            <option value="">Unassigned</option>
                            @foreach ($campaigns as $campaign)
                                <option value="{{ $campaign->id }}" @selected((string) old('campaign_id') === (string) $campaign->id)>
                                    {{ $campaign->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="um-field-hint">
                            Team lead campaign — agents inherit it.
                            @if ($campaigns->isEmpty())
                                <a href="{{ $campaignsCreateUrl }}" class="um-field-link">Create a campaign</a> first.
                            @else
                                <a href="{{ $campaignsCreateUrl }}" class="um-field-link">Manage campaigns</a>
                            @endif
                        </p>
                    </div>
                    <div class="um-field" data-create-team-lead-field hidden>
                        <label class="um-label" for="create-team-lead">Select team lead</label>
                        <select id="create-team-lead" name="team_lead_user_id" class="um-input um-select js-pretty-select"
                            data-pretty-select data-create-member-team-lead>
                            <option value="">Unassigned</option>
                        </select>
                        <p class="um-field-hint">Agent joins this team lead’s team only.</p>
                    </div>
                    <div class="um-field">
                        <label class="um-label" for="create-extension">Extension</label>
                        <input id="create-extension" type="text" name="extension_num" class="um-input"
                            inputmode="numeric" pattern="[0-9]*" maxlength="8"
                            placeholder="e.g. {{ $suggestedExtension }}"
                            value="{{ old('extension_num', $suggestedExtension) }}"
                            data-create-member-extension
                            autocomplete="off">
                        <p class="um-field-hint">
                            Type the Morpheus extension number (e.g. 1001, 1021).
                            {{ $availablePhoneLines->count() }} DID{{ $availablePhoneLines->count() === 1 ? '' : 's' }} available in the list below.
                        </p>
                    </div>
                    <div class="um-field">
                        <label class="um-label" for="create-did">DID</label>
                        <select id="create-did" name="caller_id_num" class="um-input um-select js-pretty-select"
                            data-pretty-select data-create-member-did>
                            <option value="">No DID yet</option>
                            @foreach ($availablePhoneLines as $line)
                                <option
                                    value="{{ $line['did'] }}"
                                    data-extension="{{ $line['suggested_extension'] ?? $line['extension'] ?? '' }}"
                                    @selected((string) old('caller_id_num') === (string) $line['did'])
                                >{{ $line['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="um-field-hint">Select any available DID. Choosing a DID can auto-fill the suggested extension.</p>
                    </div>
                    <div class="um-field">
                        <label class="um-label" for="create-password">Password</label>
                        <input id="create-password" type="text" name="password" required minlength="6"
                            value="{{ old('password', $defaultPassword) }}" class="um-input" autocomplete="new-password">
                    </div>
                    <div class="um-field">
                        <label class="um-label" for="create-password-confirm">Confirm password</label>
                        <input id="create-password-confirm" type="text" name="password_confirmation" required
                            minlength="6" value="{{ old('password_confirmation', $defaultPassword) }}" class="um-input" autocomplete="new-password">
                    </div>
                </div>

                <div class="create-member-modules um-module-panel hidden" data-create-member-modules>
                    @include('workflows.partials.member-module-access-fields', [
                        'prefix' => 'create',
                        'activeWorkspace' => $activeWorkspace,
                    ])
                </div>
            </div>

            <div class="member-confirm-actions um-add-member-actions">
                <button type="button" class="member-confirm-cancel" data-um-add-member-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit um-btn um-btn-primary">Create account</button>
            </div>
        </form>
    </div>
</div>
