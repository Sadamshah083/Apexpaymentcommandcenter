@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Content Analyzer')

@section('content')
    <div class="mb-8">
        <h2 class="text-2xl font-bold">Content Analyzer</h2>
        <p class="text-slate-600">Analyze email content for spam, promotion, money, and shady language patterns.</p>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <form action="{{ request()->is('admin*') ? route('admin.content.analyze') : route('portal.content.analyze') }}"
            method="POST" class="bg-white rounded-xl shadow-sm border p-6 space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1">Title (optional)</label>
                <input type="text" name="title" class="w-full border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Subject Line</label>
                <input type="text" name="subject" required placeholder="Your email subject"
                    class="w-full border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">HTML Body</label>
                <textarea name="html_body" rows="12" required placeholder="<p>Your email HTML...</p>"
                    class="w-full border rounded-lg px-3 py-2 font-mono text-sm"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Plain Text (optional)</label>
                <textarea name="text_body" rows="4" class="w-full border rounded-lg px-3 py-2 font-mono text-sm"></textarea>
            </div>
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-lg">Analyze Content</button>
        </form>

        <div>
            <h3 class="font-semibold mb-3">Category Legend</h3>
            <div class="space-y-2 text-sm">
                <div class="flex items-center gap-2"><span class="w-4 h-4 bg-red-200 rounded"></span> Spam / Shady</div>
                <div class="flex items-center gap-2"><span class="w-4 h-4 bg-orange-200 rounded"></span> Money</div>
                <div class="flex items-center gap-2"><span class="w-4 h-4 bg-amber-200 rounded"></span> Promotion</div>
                <div class="flex items-center gap-2"><span class="w-4 h-4 bg-yellow-200 rounded"></span> Urgency</div>
                <div class="flex items-center gap-2"><span class="w-4 h-4 bg-green-200 rounded"></span> Trust signals</div>
            </div>

            <x-data-table title="Recent Analyses" :paginator="$analyses" class="mt-6">
                <table>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Risk score</th>
                            <th>Date</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($analyses as $analysis)
                            <tr>
                                <td>
                                    <div class="font-medium text-slate-800">{{ $analysis->title ?? $analysis->subject }}
                                    </div>
                                    @if ($analysis->title && $analysis->subject)
                                        <div class="text-xs text-slate-500 mt-0.5">{{ Str::limit($analysis->subject, 60) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span
                                        class="px-2 py-0.5 rounded text-xs font-semibold {{ $analysis->overall_score >= 7 ? 'bg-rose-100 text-rose-800' : ($analysis->overall_score >= 4 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') }}">
                                        {{ $analysis->overall_score }}/10
                                    </span>
                                </td>
                                <td class="text-slate-500">{{ $analysis->created_at->diffForHumans() }}</td>
                                <td class="text-right">
                                    <a href="{{ request()->is('admin*') ? route('admin.content.show', $analysis) : route('portal.content.show', $analysis) }}"
                                        class="text-indigo-600 hover:text-indigo-800 font-semibold text-sm">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-8 text-slate-500">No analyses yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-data-table>
        </div>
    </div>
@endsection
