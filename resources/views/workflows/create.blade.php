@extends('layouts.admin')

@section('title', 'Import leads')

@section('content')
<div class="app-page max-w-xl mx-auto space-y-6">
    <div>
        <x-back-link :href="route('admin.workflows.index')" />
        <h1 class="app-page-title mt-2">Import leads</h1>
        <p class="app-page-subtitle">Upload a spreadsheet. Map columns, tag your list, then import — enrichment and distribution are optional later steps.</p>
    </div>

    <div class="app-card app-card-padded">
        <form method="POST"
              action="{{ route('admin.workflows.store') }}"
              enctype="multipart/form-data"
              class="space-y-5"
              data-form-loading
              data-loading-title="Uploading file"
              data-loading-message="Reading your spreadsheet and mapping columns…"
              data-loading-button-text="Uploading…">
            @csrf
            <input type="hidden" name="processing_mode" value="import_only">

            <div class="app-field">
                <label for="name" class="app-label">List name</label>
                <input type="text" name="name" id="name" required placeholder="e.g. Houston salons — June 2026" class="app-input">
                <p class="app-field-hint">Creates a HubSpot-style static list for this import.</p>
            </div>

            <div class="app-field">
                <label class="app-label">File</label>
                <div class="app-upload-zone">
                    <input type="file" name="file" id="file" required accept=".csv,.xlsx,.xls,.txt" onchange="updateFileLabel(this)">
                    <div id="upload-placeholder" class="space-y-2">
                        <div class="app-icon-circle">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-zinc-800">Drop CSV or Excel here</p>
                        <p class="text-xs text-zinc-400">Up to 10 MB · US phone numbers normalized to +1 (XXX) XXX-XXXX</p>
                    </div>
                    <div id="file-selected" class="hidden space-y-1">
                        <p class="text-sm font-bold text-zinc-900" id="file-name"></p>
                        <button type="button" onclick="resetUpload()" class="app-link text-xs">Change file</button>
                    </div>
                </div>
            </div>

            <button type="submit" class="app-btn app-btn-primary w-full">Continue to column mapping</button>
        </form>
    </div>
</div>

<script>
    function updateFileLabel(input) {
        if (!input.files?.[0]) return;
        document.getElementById('file-name').textContent = input.files[0].name;
        document.getElementById('upload-placeholder').classList.add('hidden');
        document.getElementById('file-selected').classList.remove('hidden');
    }
    function resetUpload() {
        const input = document.getElementById('file');
        input.value = '';
        document.getElementById('upload-placeholder').classList.remove('hidden');
        document.getElementById('file-selected').classList.add('hidden');
    }
</script>
@endsection
