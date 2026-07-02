@extends('layouts.admin')

@section('title', 'Upload CRM CSV')

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.crm.index') }}" class="text-indigo-600 text-sm">&larr; Back to CRM</a>
        <h2 class="text-2xl font-bold mt-2">Upload Lead Sheet</h2>
        <p class="text-slate-600 text-sm mt-1">Columns are auto-detected — works with <strong>Company Name</strong> or
            <strong>Business Name</strong>, and address in one column or split (street, city, state, zip).
        </p>
    </div>

    <div class="max-w-xl bg-white rounded-xl shadow-sm border p-6">
        <form action="{{ route('admin.crm.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium mb-1">Campaign Name *</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                    placeholder="Houston Plumbing Leads — March 2026" class="w-full border rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">CSV File *</label>
                <input type="file" name="file" accept=".csv,.txt" required class="w-full border rounded-lg px-3 py-2">
                <p class="text-xs text-slate-500 mt-1">Max 50MB. First row must be headers. Any extra columns are preserved.
                </p>
            </div>

            <div class="bg-slate-50 rounded-lg p-4 text-xs text-slate-600 space-y-2">
                <p class="font-medium text-slate-700">Smart import behavior:</p>
                <ul class="list-disc list-inside space-y-1">
                    <li>Auto-maps <strong>Company Name</strong>, <strong>Business Name</strong>, full or split address
                        columns</li>
                    <li>Re-upload updates CSV data — keeps existing research unless business details changed</li>
                    <li>Reuses research for duplicate businesses (instant, no extra API cost)</li>
                    <li>Bulk CRM uses <strong>Gemini 2.5 Flash + Google Search</strong> (fast mode)</li>
                </ul>
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
                <strong>Speed tip:</strong> Open 3–4 terminal windows and run this in each for parallel research (~3–4×
                faster):
                <code class="block mt-1 bg-white px-2 py-1 rounded text-xs">php artisan queue:work database --sleep=1</code>
            </div>

            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700">
                Upload & Start Research
            </button>
        </form>
    </div>
@endsection
