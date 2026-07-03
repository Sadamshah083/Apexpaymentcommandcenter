@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Content Analyzer')

@section('content')
    @php $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.'; @endphp

    <div class="app-page content-analyzer-page space-y-5">
        <div class="app-page-header">
            <h1 class="app-page-title">Content Analyzer</h1>
            <p class="app-page-subtitle">Analyze email content for spam, promotion, money, and shady language patterns.</p>
        </div>

        <div class="content-analyzer-panels grid gap-4 lg:grid-cols-2">
            <form action="{{ route($routePrefix . 'content.analyze') }}" method="POST"
                class="app-card app-card-padded content-analyzer-form space-y-4">
                @csrf

                <div class="app-field">
                    <label class="app-label" for="title">Title (optional)</label>
                    <input type="text" name="title" id="title" class="app-input" value="{{ old('title') }}">
                </div>

                <div class="app-field">
                    <label class="app-label" for="subject">Subject Line</label>
                    <input type="text" name="subject" id="subject" required placeholder="Your email subject"
                        class="app-input" value="{{ old('subject') }}">
                </div>

                <div class="app-field">
                    <label class="app-label" for="html_body">HTML Body</label>
                    <textarea name="html_body" id="html_body" rows="12" required placeholder="<p>Your email HTML...</p>"
                        class="app-input content-analyzer-textarea">{{ old('html_body') }}</textarea>
                </div>

                <div class="app-field">
                    <label class="app-label" for="text_body">Plain Text (optional)</label>
                    <textarea name="text_body" id="text_body" rows="4"
                        class="app-input content-analyzer-textarea">{{ old('text_body') }}</textarea>
                </div>

                <button type="submit" class="app-btn app-btn-primary">Analyze Content</button>
            </form>

            <div class="content-analyzer-side space-y-4">
                <div class="app-card app-card-padded content-analyzer-legend">
                    <h2 class="app-section-title">Category Legend</h2>
                    <ul class="content-analyzer-legend-list">
                        <li><span class="content-analyzer-swatch content-analyzer-swatch--spam"></span> Spam / Shady</li>
                        <li><span class="content-analyzer-swatch content-analyzer-swatch--money"></span> Money</li>
                        <li><span class="content-analyzer-swatch content-analyzer-swatch--promo"></span> Promotion</li>
                        <li><span class="content-analyzer-swatch content-analyzer-swatch--urgency"></span> Urgency</li>
                        <li><span class="content-analyzer-swatch content-analyzer-swatch--trust"></span> Trust signals</li>
                    </ul>
                </div>

                <x-data-table title="Recent Analyses" :paginator="$analyses" min-width="520px"
                    class="content-analyzer-data-table">
                    <table class="content-analyzer-table">
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
                                @php
                                    $riskClass = match (true) {
                                        $analysis->overall_score >= 7 => 'content-analyzer-risk--high',
                                        $analysis->overall_score >= 4 => 'content-analyzer-risk--medium',
                                        default => 'content-analyzer-risk--low',
                                    };
                                @endphp
                                <tr>
                                    <td>
                                        <div class="content-analyzer-subject">
                                            {{ $analysis->title ?? $analysis->subject }}
                                        </div>
                                        @if ($analysis->title && $analysis->subject)
                                            <div class="content-analyzer-subject-meta">
                                                {{ Str::limit($analysis->subject, 60) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="content-analyzer-risk {{ $riskClass }}">
                                            {{ $analysis->overall_score }}/10
                                        </span>
                                    </td>
                                    <td class="content-analyzer-date">{{ $analysis->created_at->diffForHumans() }}</td>
                                    <td class="text-right">
                                        <a href="{{ route($routePrefix . 'content.show', $analysis) }}"
                                            class="content-analyzer-action">View</a>
                                    </td>
                                </tr>
                            @empty
                                <tr class="content-analyzer-empty-row">
                                    <td colspan="4">
                                        <div class="content-analyzer-empty">
                                            <p class="content-analyzer-empty-title">No analyses yet.</p>
                                            <p class="content-analyzer-empty-desc">Paste email content above and run your
                                                first analysis.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-data-table>
            </div>
        </div>
    </div>
@endsection
