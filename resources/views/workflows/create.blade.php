@extends('layouts.admin')

@section('title', 'Upload pipeline file')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-extrabold text-warmgrey-900">New lead pipeline</h1>
            <p class="text-sm text-warmgrey-500 mt-1">Upload a CSV or Excel spreadsheet containing business listings.</p>
        </div>
        <a href="{{ route('admin.workflows.index') }}" class="text-xs text-warmgrey-500 hover:text-warmgrey-900 font-bold flex items-center underline">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            Back to Pipelines
        </a>
    </div>

    <!-- Upload Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-warmgrey-200 p-8">
        <form method="POST"
              action="{{ route('admin.workflows.store') }}"
              enctype="multipart/form-data"
              class="space-y-6"
              data-form-loading
              data-loading-title="Uploading spreadsheet"
              data-loading-message="Uploading your file and mapping columns with AI. This can take a moment."
              data-loading-button-text="Processing…">
            @csrf

            <!-- Pipeline Name -->
            <div class="space-y-2">
                <label for="name" class="block text-xs font-bold uppercase tracking-wider text-warmgrey-500">Pipeline Campaign Name</label>
                <input type="text" name="name" id="name" required placeholder="e.g., California Salons June 2026" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-warmgrey-500 focus:bg-white transition-all text-sm">
            </div>

            <!-- Drag & Drop Upload Zone -->
            <div class="space-y-2">
                <label class="block text-xs font-bold uppercase tracking-wider text-warmgrey-500">Spreadsheet File (CSV, XLSX, XLS)</label>
                <div class="relative group border-2 border-dashed border-warmgrey-200 hover:border-warmgrey-500 rounded-2xl p-8 text-center transition-all bg-cream-50/50 hover:bg-cream-100 cursor-pointer">
                    <input type="file" name="file" id="file" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="updateFileLabel(this)">
                    
                    <div class="space-y-3" id="upload-placeholder">
                        <div class="w-12 h-12 rounded-full bg-cream-200 text-warmgrey-900 flex items-center justify-center mx-auto group-hover:scale-110 transition-transform border border-warmgrey-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                        </div>
                        <div class="text-sm font-bold text-warmgrey-900">Drag your file here, or <span class="text-warmgrey-500 underline">browse</span></div>
                        <div class="text-xs text-warmgrey-500">Supports CSV, XLSX, XLS up to 10MB</div>
                    </div>

                    <div class="hidden space-y-2 text-center" id="file-selected">
                        <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto border border-emerald-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <div class="text-sm font-bold text-warmgrey-900" id="file-name">Selected File</div>
                        <button type="button" onclick="resetUpload()" class="text-xs text-warmgrey-500 hover:text-warmgrey-900 underline font-semibold">Change file</button>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" id="pipeline-upload-submit" class="w-full py-3 btn-primary text-white font-bold rounded-xl shadow-lg transition-all flex items-center justify-center">
                Configure Mappings & Sheet
                <svg class="w-5 h-5 ml-2 submit-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
            </button>
        </form>
    </div>
</div>

<script>
    function updateFileLabel(input) {
        if (input.files && input.files[0]) {
            const fileName = input.files[0].name;
            document.getElementById('file-name').textContent = fileName;
            document.getElementById('upload-placeholder').classList.add('hidden');
            document.getElementById('file-selected').classList.remove('hidden');
        }
    }

    function resetUpload() {
        const input = document.getElementById('file');
        input.value = '';
        document.getElementById('upload-placeholder').classList.remove('hidden');
        document.getElementById('file-selected').classList.add('hidden');
    }
</script>
@endsection
