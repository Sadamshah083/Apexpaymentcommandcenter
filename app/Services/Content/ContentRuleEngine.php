<?php

namespace App\Services\Content;

use App\Models\SpamRule;
use Illuminate\Support\Facades\Cache;

class ContentRuleEngine
{
    public function analyze(string $subject, string $htmlBody, string $textBody = ''): array
    {
        $textBody = $textBody ?: $this->htmlToText($htmlBody);
        $rules = Cache::remember('spam_rules_active', 3600, fn () => SpamRule::where('is_active', true)->get());

        $scores = [
            'spam' => 0,
            'promotion' => 0,
            'money' => 0,
            'shady' => 0,
            'urgency' => 0,
            'trust_positive' => 0,
            'structural' => 0,
        ];

        $highlights = [];
        $suggestions = [];
        $triggeredRules = [];

        foreach ($rules as $rule) {
            $targets = $this->getTargets($rule->target, $subject, $htmlBody, $textBody);

            foreach ($targets as $targetName => $content) {
                if ($content === '') {
                    continue;
                }

                $matches = $this->matchRule($rule, $content);

                foreach ($matches as $match) {
                    $weight = (float) $rule->weight;
                    if ($rule->category === 'trust_positive') {
                        $scores['trust_positive'] += abs($weight);
                    } else {
                        $scores[$rule->category] = ($scores[$rule->category] ?? 0) + $weight;
                    }

                    $highlights[] = [
                        'category' => $rule->category,
                        'target' => $targetName,
                        'text' => $match['text'],
                        'offset' => $match['offset'],
                        'length' => $match['length'],
                        'weight' => $weight,
                        'rule' => $rule->name,
                    ];

                    if ($rule->suggestion && ! in_array($rule->suggestion, $suggestions, true)) {
                        $suggestions[] = $rule->suggestion;
                    }

                    $triggeredRules[] = [
                        'name' => $rule->name,
                        'category' => $rule->category,
                        'weight' => $weight,
                        'description' => $rule->description,
                    ];
                }
            }
        }

        $structural = $this->analyzeStructure($subject, $htmlBody, $textBody);
        $scores['structural'] = $structural['score'];
        $highlights = array_merge($highlights, $structural['highlights']);
        $suggestions = array_merge($suggestions, $structural['suggestions']);

        $rawSpamScore = max(0,
            $scores['spam'] + $scores['promotion'] + $scores['money']
            + $scores['shady'] + $scores['urgency'] + $scores['structural']
            - $scores['trust_positive']
        );

        $threshold = config('email_checker.content.spam_threshold', 5.0);
        $maxScore = config('email_checker.content.max_score', 10.0);
        $overallScore = min($maxScore, round($rawSpamScore, 2));
        $mailTesterStyle = max(0, round(10 - $overallScore, 1));

        return [
            'scores' => $scores,
            'spam_score' => $rawSpamScore,
            'overall_score' => $overallScore,
            'mail_tester_score' => $mailTesterStyle,
            'risk_level' => $rawSpamScore >= $threshold ? 'high' : ($rawSpamScore >= 3 ? 'medium' : 'low'),
            'highlights' => $highlights,
            'suggestions' => array_values(array_unique($suggestions)),
            'triggered_rules' => $triggeredRules,
        ];
    }

    protected function getTargets(string $target, string $subject, string $html, string $text): array
    {
        return match ($target) {
            'subject' => ['subject' => $subject],
            'body' => ['body' => $text ?: $html],
            'html' => ['html' => $html],
            default => [
                'subject' => $subject,
                'body' => $text ?: $html,
            ],
        };
    }

    protected function matchRule(SpamRule $rule, string $content): array
    {
        $matches = [];

        if ($rule->match_type === 'regex') {
            if (@preg_match_all($rule->pattern, $content, $found, PREG_OFFSET_CAPTURE)) {
                foreach ($found[0] as $match) {
                    $matches[] = [
                        'text' => $match[0],
                        'offset' => $match[1],
                        'length' => strlen($match[0]),
                    ];
                }
            }
        } elseif ($rule->match_type === 'contains') {
            $pos = 0;
            $needle = strtolower($rule->pattern);
            $haystack = strtolower($content);
            while (($pos = strpos($haystack, $needle, $pos)) !== false) {
                $matches[] = [
                    'text' => substr($content, $pos, strlen($rule->pattern)),
                    'offset' => $pos,
                    'length' => strlen($rule->pattern),
                ];
                $pos += strlen($needle);
            }
        }

        return $matches;
    }

    protected function analyzeStructure(string $subject, string $html, string $text): array
    {
        $score = 0;
        $highlights = [];
        $suggestions = [];

        if ($html && ! $text) {
            $score += 1.5;
            $suggestions[] = 'Add a plain-text alternative part to your email (HTML-only increases spam score)';
        }

        if (preg_match('/!{2,}/', $subject)) {
            $score += 1.0;
            $suggestions[] = 'Reduce exclamation marks in the subject line';
        }

        if (preg_match('/^[A-Z\s\d!?.,\'"-]+$/', $subject) && strlen($subject) > 5) {
            $score += 1.4;
            $suggestions[] = 'Avoid ALL CAPS in subject line';
        }

        $linkCount = preg_match_all('/<a\s/i', $html);
        if ($linkCount > 10) {
            $score += 1.0;
            $suggestions[] = "Too many links ({$linkCount}) — reduce to improve deliverability";
        }

        if (preg_match('/bit\.ly|tinyurl|goo\.gl|t\.co/i', $html.$text)) {
            $score += 0.8;
            $suggestions[] = 'Avoid URL shorteners — use full branded links';
        }

        if (! preg_match('/unsubscribe/i', $html.$text)) {
            $score += 1.2;
            $suggestions[] = 'Add a visible unsubscribe link (required for bulk senders per Google/Yahoo 2024 rules)';
        }

        $imageCount = preg_match_all('/<img\s/i', $html);
        $textLength = strlen(strip_tags($html)) ?: strlen($text);
        if ($imageCount > 0 && $textLength < 200) {
            $score += 1.5;
            $suggestions[] = 'High image-to-text ratio — add more text content';
        }

        return [
            'score' => $score,
            'highlights' => $highlights,
            'suggestions' => $suggestions,
        ];
    }

    protected function htmlToText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text ?? '');
    }
}
