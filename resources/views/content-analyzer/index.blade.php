@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Content Analyzer')

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold">Content Analyzer</h2>
    <p class="text-slate-600">Analyze email content for spam, promotion, money, and shady language patterns.</p>
</div>

<div class="grid md:grid-cols-2 gap-6">
    <form action="{{ request()->is('admin*') ? route('admin.content.analyze') : route('portal.content.analyze') }}" method="POST" class="bg-white rounded-xl shadow-sm border p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1">Title (optional)</label>
            <input type="text" name="title" class="w-full border rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Subject Line</label>
            <input type="text" name="subject" required placeholder="Your email subject" class="w-full border rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">HTML Body</label>
            <textarea name="html_body" rows="12" required placeholder="<p>Your email HTML...</p>" class="w-full border rounded-lg px-3 py-2 font-mono text-sm"></textarea>
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

        <h3 class="font-semibold mt-6 mb-3">Recent Analyses</h3>
        @forelse($analyses as $analysis)
            <a href="{{ request()->is('admin*') ? route('admin.content.show', $analysis) : route('portal.content.show', $analysis) }}" class="block py-2 border-b text-sm hover:text-indigo-600">
                {{ $analysis->title ?? $analysis->subject }}
                <span class="text-slate-500 block">Risk: {{ $analysis->overall_score }}/10</span>
            </a>
        @empty
            <p class="text-slate-500 text-sm">No analyses yet.</p>
        @endforelse
        <x-pagination :paginator="$analyses" class="mt-2" />
    </div>
</div>
@endsection
