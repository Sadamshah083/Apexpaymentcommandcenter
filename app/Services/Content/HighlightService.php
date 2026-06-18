<?php

namespace App\Services\Content;

class HighlightService
{
    protected array $categoryColors = [
        'spam' => 'bg-red-200 text-red-900',
        'shady' => 'bg-red-300 text-red-950',
        'money' => 'bg-orange-200 text-orange-900',
        'promotion' => 'bg-amber-200 text-amber-900',
        'urgency' => 'bg-yellow-200 text-yellow-900',
        'trust_positive' => 'bg-green-200 text-green-900',
        'structural' => 'bg-purple-200 text-purple-900',
    ];

    public function applyHighlights(string $content, array $highlights, string $target = 'body'): string
    {
        $filtered = array_filter($highlights, fn ($h) => ($h['target'] ?? 'body') === $target);
        usort($filtered, fn ($a, $b) => ($b['offset'] ?? 0) <=> ($a['offset'] ?? 0));

        $result = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        foreach ($filtered as $highlight) {
            $offset = $highlight['offset'] ?? 0;
            $length = $highlight['length'] ?? 0;
            $category = $highlight['category'] ?? 'spam';
            $color = $this->categoryColors[$category] ?? 'bg-gray-200';

            $before = mb_substr($result, 0, $offset);
            $match = mb_substr($result, $offset, $length);
            $after = mb_substr($result, $offset + $length);

            if ($match !== '') {
                $result = $before.'<mark class="'.$color.' px-0.5 rounded" title="'.htmlspecialchars($category).'">'.$match.'</mark>'.$after;
            }
        }

        return $result;
    }

    public function getCategoryColors(): array
    {
        return $this->categoryColors;
    }
}
