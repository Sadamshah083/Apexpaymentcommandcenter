@extends('layouts.admin')

@section('title', 'Import leads')

@section('content')
<div class="app-page max-w-xl mx-auto space-y-6">
    <div>
        <x-back-link :href="route('admin.dashboard', ['section' => 'imports'])" />
        <h1 class="app-page-title mt-2">Import leads</h1>
        <p class="app-page-subtitle">Upload a spreadsheet under a campaign. Map columns, then enrich and assign from the Command Center.</p>
    </div>

    <div class="app-card app-card-padded">
        <form method="POST" action="{{ route('admin.workflows.store') }}" enctype="multipart/form-data" class="space-y-5" data-form-loading data-workflow-upload>
            @csrf
            <input type="hidden" name="processing_mode" value="import_only">

            <div class="app-field">
                <label class="app-label">Campaign</label>
                <select name="campaign_id" id="campaign_id" class="app-input" onchange="document.getElementById('new-campaign-wrap').classList.toggle('hidden', this.value !== '')">
                    <option value="">— Create new campaign —</option>
                    @foreach ($campaigns as $campaign)
                        <option value="{{ $campaign->id }}" @selected(old('campaign_id') == $campaign->id)>{{ $campaign->name }}</option>
                    @endforeach
                </select>
            </div>

            <div id="new-campaign-wrap" class="app-field {{ old('campaign_id') ? 'hidden' : '' }}">
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
                <div class="app-upload-zone">
                    <input type="file" name="file" id="file" required accept=".csv,.xlsx,.xls,.txt">
                    <div id="upload-placeholder" class="space-y-2">
                        <p class="text-sm font-semibold text-zinc-800">Drop CSV or Excel here</p>
                        <p class="text-xs text-zinc-400">Up to 10 MB</p>
                    </div>
                </div>
            </div>

            <button type="submit" class="app-btn app-btn-primary w-full">Continue to column mapping</button>
        </form>
    </div>
</div>
@endsection
