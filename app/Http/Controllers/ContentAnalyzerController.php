<?php

namespace App\Http\Controllers;

use App\Models\ContentAnalysis;
use App\Services\Content\ContentRuleEngine;
use App\Services\Content\HighlightService;
use Illuminate\Http\Request;

class ContentAnalyzerController extends Controller
{
    public function index()
    {
        $analyses = ContentAnalysis::latest()->paginate(10);

        return view('content-analyzer.index', compact('analyses'));
    }

    public function analyze(Request $request, ContentRuleEngine $engine, HighlightService $highlighter)
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'html_body' => 'required|string',
            'text_body' => 'nullable|string',
        ]);

        $result = $engine->analyze(
            $request->subject,
            $request->html_body,
            $request->text_body ?? '',
        );

        $analysis = ContentAnalysis::create([
            'title' => $request->title ?? 'Content Analysis '.now()->format('Y-m-d H:i'),
            'subject' => $request->subject,
            'html_body' => $request->html_body,
            'text_body' => $request->text_body,
            'scores' => $result['scores'],
            'highlights' => $result['highlights'],
            'suggestions' => $result['suggestions'],
            'spam_score' => $result['spam_score'],
            'overall_score' => $result['overall_score'],
        ]);

        $highlightedSubject = $highlighter->applyHighlights($request->subject, $result['highlights'], 'subject');
        $highlightedBody = $highlighter->applyHighlights(
            $request->text_body ?: strip_tags($request->html_body),
            $result['highlights'],
            'body'
        );

        return view('content-analyzer.show', [
            'analysis' => $analysis,
            'result' => $result,
            'highlightedSubject' => $highlightedSubject,
            'highlightedBody' => $highlightedBody,
            'categoryColors' => $highlighter->getCategoryColors(),
        ]);
    }

    public function show(ContentAnalysis $contentAnalysis, HighlightService $highlighter)
    {
        $engine = app(ContentRuleEngine::class);
        $result = $engine->analyze(
            $contentAnalysis->subject ?? '',
            $contentAnalysis->html_body ?? '',
            $contentAnalysis->text_body ?? '',
        );

        $highlightedSubject = $highlighter->applyHighlights($contentAnalysis->subject ?? '', $result['highlights'], 'subject');
        $highlightedBody = $highlighter->applyHighlights(
            $contentAnalysis->text_body ?: strip_tags($contentAnalysis->html_body ?? ''),
            $result['highlights'],
            'body'
        );

        return view('content-analyzer.show', [
            'analysis' => $contentAnalysis,
            'result' => $result,
            'highlightedSubject' => $highlightedSubject,
            'highlightedBody' => $highlightedBody,
            'categoryColors' => $highlighter->getCategoryColors(),
        ]);
    }
}
