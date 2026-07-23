@extends('layouts.admin')

@section('title', 'Campaigns')

@section('content')
<div class="app-page campaigns-page">
    <div class="app-page-header flex flex-row items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="app-page-title">Campaigns</h1>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <a href="{{ route('admin.dashboard') }}" class="app-btn app-btn-secondary">Dashboard</a>
            <x-import-file-link />
        </div>
    </div>

    <div class="app-card app-card-padded mb-6 campaigns-create-bar">
        <form method="POST" action="{{ route('admin.campaigns.store') }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <input type="text" name="name" required maxlength="100" placeholder="New campaign name" class="app-input flex-1">
            <button type="submit" class="app-btn app-btn-primary shrink-0">Create campaign</button>
        </form>
    </div>

    @if ($campaigns->isEmpty())
        <div class="app-card app-card-padded app-empty-state">
            <p class="app-empty-state-title">No campaigns yet</p>
            <p class="app-empty-state-desc">Create a campaign, then import a file and select it during upload.</p>
        </div>
    @else
        <div class="app-data-table campaigns-table-wrap">
            <div class="app-data-table-header">
                <h2 class="app-data-table-title">All campaigns</h2>
            </div>
            <div class="app-table-wrap" data-min-width="1080px">
                <table class="campaigns-table">
                    <thead>
                        <tr>
                            <th class="col-name">Campaign</th>
                            <th class="col-leads">Leads</th>
                            <th class="col-imports">Imports</th>
                            <th class="col-enriched">Enriched</th>
                            <th class="col-assigned">Assigned</th>
                            <th class="col-calls">Calls</th>
                            <th class="col-connected">Connected</th>
                            <th class="col-rate">Connect rate</th>
                            <th class="col-action text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $campaign)
                            @php $kpi = ($campaignKpis[$campaign->id] ?? null) ?: ['dials' => 0, 'connected' => 0, 'connect_rate' => 0]; @endphp
                            <tr>
                                <td class="col-name">
                                    <a href="{{ route('admin.campaigns.show', $campaign) }}" class="campaigns-table-name" title="{{ $campaign->name }}">
                                        {{ $campaign->name }}
                                    </a>
                                </td>
                                <td class="col-leads">{{ number_format($campaign->leads_count) }}</td>
                                <td class="col-imports">{{ number_format($campaign->imports_count) }}</td>
                                <td class="col-enriched">{{ number_format($campaign->enriched_count) }}</td>
                                <td class="col-assigned">{{ number_format($campaign->assigned_count) }}</td>
                                <td class="col-calls">{{ number_format($kpi['dials'] ?? 0) }}</td>
                                <td class="col-connected">{{ number_format($kpi['connected'] ?? 0) }}</td>
                                <td class="col-rate">{{ number_format($kpi['connect_rate'] ?? 0, 1) }}%</td>
                                <td class="col-action text-right">
                                    <div class="campaigns-table-actions">
                                        <a href="{{ route('admin.campaigns.show', $campaign) }}"
                                            class="campaigns-action-icon"
                                            title="Open"
                                            aria-label="Open {{ $campaign->name }}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                                <polyline points="15 3 21 3 21 9" />
                                                <line x1="10" y1="14" x2="21" y2="3" />
                                            </svg>
                                        </a>
                                        <button type="button"
                                            class="campaigns-action-icon"
                                            title="Edit"
                                            aria-label="Edit {{ $campaign->name }}"
                                            data-campaign-edit
                                            data-campaign-id="{{ $campaign->id }}"
                                            data-campaign-name="{{ $campaign->name }}"
                                            data-campaign-update-url="{{ route('admin.campaigns.update', $campaign) }}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M12 20h9" />
                                                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                            </svg>
                                        </button>
                                        <form method="POST"
                                            action="{{ route('admin.campaigns.destroy', $campaign) }}"
                                            class="campaigns-table-delete-form"
                                            onsubmit="return confirm('Delete campaign \'{{ addslashes($campaign->name) }}\'? Linked imports and leads will stay, but will no longer belong to this campaign.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="campaigns-action-icon campaigns-action-icon--danger"
                                                title="Delete"
                                                aria-label="Delete {{ $campaign->name }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <polyline points="3 6 5 6 21 6" />
                                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                    <path d="M10 11v6" />
                                                    <path d="M14 11v6" />
                                                    <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<div id="campaign-edit-modal" class="campaigns-edit-modal" hidden>
    <div class="campaigns-edit-modal__backdrop" data-campaign-edit-cancel></div>
    <div class="campaigns-edit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="campaign-edit-title">
        <div class="campaigns-edit-modal__header">
            <div class="campaigns-edit-modal__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9" />
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                </svg>
            </div>
            <div>
                <h3 class="campaigns-edit-modal__title" id="campaign-edit-title">Edit campaign</h3>
                <p class="campaigns-edit-modal__subtitle">Update the campaign name, then save.</p>
            </div>
            <button type="button" class="campaigns-edit-modal__close" data-campaign-edit-cancel aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>
        <form method="POST" id="campaign-edit-form" class="campaigns-edit-modal__form">
            @csrf
            @method('PUT')
            <label class="campaigns-edit-modal__label" for="campaign-edit-name">Campaign name</label>
            <input type="text" id="campaign-edit-name" name="name" required maxlength="100" class="campaigns-edit-modal__input" autocomplete="off">
            <div class="campaigns-edit-modal__actions">
                <button type="button" class="campaigns-edit-modal__btn campaigns-edit-modal__btn--ghost" data-campaign-edit-cancel>Cancel</button>
                <button type="submit" class="campaigns-edit-modal__btn campaigns-edit-modal__btn--primary">Save changes</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('campaign-edit-modal');
    const form = document.getElementById('campaign-edit-form');
    const nameInput = document.getElementById('campaign-edit-name');
    if (!modal || !form || !nameInput) return;

    const openModal = () => {
        modal.hidden = false;
        document.body.classList.add('campaigns-edit-modal-open');
        window.setTimeout(() => {
            nameInput.focus();
            nameInput.select();
        }, 0);
    };

    const closeModal = () => {
        modal.hidden = true;
        document.body.classList.remove('campaigns-edit-modal-open');
    };

    document.querySelectorAll('[data-campaign-edit]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.action = btn.dataset.campaignUpdateUrl || '';
            nameInput.value = btn.dataset.campaignName || '';
            openModal();
        });
    });

    modal.querySelectorAll('[data-campaign-edit-cancel]').forEach((el) => {
        el.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
</script>
@endsection
