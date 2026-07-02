@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Upload Email Batch')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">New verification batch</h1>
                <p class="text-sm text-slate-500 mt-1">Upload emails for {{ $workspace->name }}. We check syntax, MX records,
                    disposable domains, and SMTP reachability.</p>
            </div>
            <a href="{{ request()->is('admin*') ? route('admin.lists.index') : route('portal.lists.index') }}"
                class="text-xs text-slate-500 hover:text-slate-900 font-bold underline">Back to lists</a>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-8">
            <form action="{{ request()->is('admin*') ? route('admin.lists.store') : route('portal.lists.store') }}"
                method="POST" enctype="multipart/form-data" class="space-y-6" data-form-loading
                data-loading-title="Uploading email list"
                data-loading-message="Parsing your file and queuing verification jobs…"
                data-loading-button-text="Uploading…">
                @csrf

                <div class="space-y-2">
                    <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-500">Batch
                        name</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required
                        placeholder="e.g., June outreach list"
                        class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500">Email file (CSV or
                        TXT)</label>
                    <div
                        class="relative group border-2 border-dashed border-slate-200 hover:border-slate-400 rounded-2xl p-8 text-center transition-all bg-slate-50/50 hover:bg-slate-50 cursor-pointer">
                        <input type="file" name="file" id="file" accept=".csv,.txt" required
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                            onchange="updateFileLabel(this)">

                        <div class="space-y-3" id="upload-placeholder">
                            <div
                                class="w-12 h-12 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center mx-auto group-hover:scale-105 transition-transform">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                            </div>
                            <div class="text-sm font-bold text-slate-800">Drag your file here, or <span
                                    class="text-indigo-600 underline">browse</span></div>
                            <div class="text-xs text-slate-500">One email per line, or CSV with email in the first column.
                                Max 50MB.</div>
                        </div>

                        <div class="hidden space-y-2 text-center" id="file-selected">
                            <div
                                class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto border border-emerald-200">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="text-sm font-bold text-slate-800" id="file-name">Selected file</div>
                            <button type="button" onclick="resetUpload()"
                                class="text-xs text-slate-500 hover:text-slate-800 underline font-semibold">Change
                                file</button>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label for="notes" class="block text-xs font-bold uppercase tracking-wider text-slate-500">Notes
                        (optional)</label>
                    <textarea name="notes" id="notes" rows="3"
                        class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">{{ old('notes') }}</textarea>
                </div>

                <button type="submit"
                    class="w-full py-3 bg-slate-900 hover:bg-slate-800 text-white font-bold rounded-xl transition-colors">
                    Upload &amp; start verification
                </button>
            </form>
        </div>
    </div>

    <script>
        function updateFileLabel(input) {
            if (input.files && input.files[0]) {
                document.getElementById('file-name').textContent = input.files[0].name;
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
