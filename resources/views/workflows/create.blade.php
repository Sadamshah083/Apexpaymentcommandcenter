@extends('layouts.admin')

@section('title', 'Import leads')

@section('content')
@php
    $selectedCampaignId = old('campaign_id', $campaigns->isNotEmpty() ? (string) $campaigns->first()->id : '');
    $selectedCampaignLabel = $campaigns->firstWhere('id', (int) $selectedCampaignId)?->name
        ?? ($selectedCampaignId === '' ? '— Create new campaign —' : 'Select campaign');
@endphp
<div id="workspace-sync-page" data-sync-scope="off" class="hidden" aria-hidden="true"></div>
<div class="app-page max-w-xl mx-auto space-y-6 import-create-page">
    <div>
        <x-back-link :href="route('admin.workflows.index')" />
        <h1 class="app-page-title mt-2">Import leads</h1>
        <p class="app-page-subtitle">Upload a spreadsheet under a campaign. Map columns, then enrich and assign from the Command Center.</p>
    </div>

    <div class="app-card app-card-padded">
        <form method="POST" action="{{ route('admin.workflows.store') }}" enctype="multipart/form-data" class="space-y-5" data-form-loading data-workflow-upload>
            @csrf
            <input type="hidden" name="processing_mode" value="import_only">

            <div class="app-field">
                <label id="campaign-label" class="app-label">Campaign</label>
                <div class="import-campaign-dropdown" data-import-campaign-dropdown>
                    <select name="campaign_id" id="campaign_id" class="import-campaign-dropdown__native" data-import-campaign-select tabindex="-1" aria-hidden="true">
                        @foreach ($campaigns as $campaign)
                            <option value="{{ $campaign->id }}" @selected((string) $selectedCampaignId === (string) $campaign->id)>
                                {{ $campaign->name }}
                            </option>
                        @endforeach
                        <option value="" @selected($selectedCampaignId === '')>— Create new campaign —</option>
                    </select>

                    <button type="button" class="import-campaign-dropdown__trigger" aria-haspopup="listbox"
                        aria-expanded="false" aria-labelledby="campaign-label">
                        <span class="import-campaign-dropdown__value" data-import-campaign-label>{{ $selectedCampaignLabel }}</span>
                        <svg class="import-campaign-dropdown__chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div class="import-campaign-dropdown__menu" role="listbox" hidden>
                        @foreach ($campaigns as $campaign)
                            <button type="button"
                                class="import-campaign-dropdown__option {{ (string) $selectedCampaignId === (string) $campaign->id ? 'is-selected' : '' }}"
                                role="option"
                                data-import-campaign-option
                                data-value="{{ $campaign->id }}"
                                aria-selected="{{ (string) $selectedCampaignId === (string) $campaign->id ? 'true' : 'false' }}">
                                <span class="import-campaign-dropdown__option-name">{{ $campaign->name }}</span>
                            </button>
                        @endforeach
                        <button type="button"
                            class="import-campaign-dropdown__option import-campaign-dropdown__option--create {{ $selectedCampaignId === '' ? 'is-selected' : '' }}"
                            role="option"
                            data-import-campaign-option
                            data-value=""
                            aria-selected="{{ $selectedCampaignId === '' ? 'true' : 'false' }}">
                            <span class="import-campaign-dropdown__option-name">Create new campaign</span>
                        </button>
                    </div>
                </div>
                @if ($campaigns->isNotEmpty())
                    <p class="app-field-hint">Choose an existing campaign, or create a new one.</p>
                @endif
            </div>

            <div id="new-campaign-wrap" class="app-field {{ $selectedCampaignId !== '' ? 'hidden' : '' }}">
                <label for="campaign_name" class="app-label">New campaign name</label>
                <input type="text" name="campaign_name" id="campaign_name" class="app-input" placeholder="e.g. Houston Q2 2026" value="{{ old('campaign_name') }}">
            </div>

            <div class="app-field">
                <label for="name" class="app-label">Import file name</label>
                <input type="text" name="name" id="name" required placeholder="e.g. Houston salons batch 1" class="app-input" value="{{ old('name') }}">
                <p class="app-field-hint">Label for this specific upload within the campaign.</p>
            </div>

            <div class="app-field">
                <label class="app-label">File</label>
                <div class="app-upload-zone import-upload-zone">
                    <input type="file" name="file" id="file" required accept=".csv,.xlsx,.xls,.txt">
                    <div id="upload-placeholder" class="space-y-2">
                        <p class="import-upload-zone__title">Drop CSV or Excel here</p>
                        <p class="import-upload-zone__hint">Up to 10 MB</p>
                    </div>
                </div>
            </div>

            <button type="submit" class="app-btn app-btn-success w-full">Continue to column mapping</button>
        </form>
    </div>
</div>
@endsection
