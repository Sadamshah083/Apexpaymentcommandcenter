@extends('layouts.admin')

@section('title', 'Upload CRM CSV')

@section('content')
    <div class="app-page crm-page space-y-5">
        <div class="app-page-header">
            <a href="{{ route('admin.crm.index') }}" class="crm-back-link">&larr; Back to CRM</a>
            <h1 class="app-page-title mt-2">Upload Lead Sheet</h1>
            <p class="app-page-subtitle">Columns are auto-detected — works with <strong>Company Name</strong> or
                <strong>Business Name</strong>, and address in one column or split (street, city, state, zip).
            </p>
        </div>

        <div class="app-card app-card-padded crm-upload-card max-w-xl">
            <form action="{{ route('admin.crm.store') }}" method="POST" enctype="multipart/form-data"
                class="crm-upload-form">
                @csrf

                <div class="app-field">
                    <label class="app-label" for="name">Campaign Name *</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required
                        placeholder="Houston Plumbing Leads — March 2026" class="app-input">
                </div>

                <div class="app-field">
                    <label class="app-label" for="file">CSV File *</label>
                    <input type="file" name="file" id="file" accept=".csv,.txt" required class="app-input">
                    <p class="crm-field-hint">Max 50MB. First row must be headers. Any extra columns are preserved.</p>
                </div>

                <div class="crm-info-box">
                    <p class="crm-info-title">Smart import behavior:</p>
                    <ul class="crm-info-list">
                        <li>Auto-maps <strong>Company Name</strong>, <strong>Business Name</strong>, full or split address
                            columns</li>
                        <li>Re-upload updates CSV data — keeps existing research unless business details changed</li>
                        <li>Reuses research for duplicate businesses (instant, no extra API cost)</li>
                        <li>Bulk CRM uses <strong>Gemini 2.5 Flash + Google Search</strong> (fast mode)</li>
                    </ul>
                </div>

                <div class="crm-alert-box">
                    <strong>Speed tip:</strong> Open 3–4 terminal windows and run this in each for parallel research (~3–4×
                    faster):
                    <code class="crm-alert-code">php artisan queue:work database --sleep=1</code>
                </div>

                <button type="submit" class="app-btn app-btn-primary">Upload &amp; Start Research</button>
            </form>
        </div>
    </div>
@endsection
