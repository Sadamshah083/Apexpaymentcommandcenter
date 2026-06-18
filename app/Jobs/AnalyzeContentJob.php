<?php

namespace App\Jobs;

use App\Models\ContentAnalysis;
use App\Services\Content\ContentRuleEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeContentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $analysisId,
    ) {}

    public function handle(ContentRuleEngine $engine): void
    {
        $analysis = ContentAnalysis::findOrFail($this->analysisId);
        $result = $engine->analyze(
            $analysis->subject ?? '',
            $analysis->html_body ?? '',
            $analysis->text_body ?? '',
        );

        $analysis->update([
            'scores' => $result['scores'],
            'highlights' => $result['highlights'],
            'suggestions' => $result['suggestions'],
            'spam_score' => $result['spam_score'],
            'overall_score' => $result['overall_score'],
        ]);
    }
}
