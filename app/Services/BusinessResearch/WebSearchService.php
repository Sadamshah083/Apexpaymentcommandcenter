<?php

namespace App\Services\BusinessResearch;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebSearchService
{
    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    public function searchDuckDuckGo(string $query, int $maxResults = 8): array
    {
        $results = $this->searchDuckDuckGoHtml($query, $maxResults);

        if ($results !== []) {
            return $results;
        }

        return $this->searchDuckDuckGoLite($query, $maxResults);
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    protected function searchDuckDuckGoHtml(string $query, int $maxResults): array
    {
        try {
            $request = Http::timeout(25)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ]);

            if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->asForm()->post('https://html.duckduckgo.com/html/', [
                'q' => $query,
            ]);

            if (! $response->successful()) {
                return [];
            }

            return $this->parseDuckDuckGoHtml($response->body(), $maxResults);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    protected function searchDuckDuckGoLite(string $query, int $maxResults): array
    {
        try {
            $request = Http::timeout(25)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ApexOneEnrichment/1.0)',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ]);

            if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->asForm()->post('https://lite.duckduckgo.com/lite/', [
                'q' => $query,
            ]);

            if (! $response->successful()) {
                return [];
            }

            return $this->parseDuckDuckGoLiteHtml($response->body(), $maxResults);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    protected function parseDuckDuckGoLiteHtml(string $html, int $maxResults): array
    {
        $results = [];

        if (preg_match_all(
            '/<a[^>]+rel="nofollow"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach (array_slice($matches, 0, $maxResults * 2) as $match) {
                $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
                $title = trim(strip_tags(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8')));

                if ($title === '' || ! $this->isUsefulUrl($url)) {
                    continue;
                }

                $results[] = [
                    'title' => $title,
                    'url' => $url,
                    'snippet' => '',
                ];

                if (count($results) >= $maxResults) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    protected function parseDuckDuckGoHtml(string $html, int $maxResults): array
    {
        $results = [];

        if (preg_match_all(
            '/<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>.*?<a[^>]+class="result__snippet"[^>]*>(.*?)<\/a>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach (array_slice($matches, 0, $maxResults) as $match) {
                $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
                $title = strip_tags(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
                $snippet = strip_tags(html_entity_decode($match[3], ENT_QUOTES, 'UTF-8'));

                if ($title !== '' && $this->isUsefulUrl($url)) {
                    $results[] = [
                        'title' => trim($title),
                        'url' => trim($url),
                        'snippet' => trim($snippet),
                    ];
                }
            }
        }

        return $results;
    }

    protected function isUsefulUrl(string $url): bool
    {
        return ! str_contains($url, 'duckduckgo.com/y.js');
    }

    /**
     * @return array<int, string>
     */
    public function buildSearchQueries(string $businessName, ?string $address, ?string $website): array
    {
        $name = trim($businessName);
        $location = $address ? ' '.preg_replace('/\s+/', ' ', trim($address)) : '';
        $cityState = $this->extractCityState($address);
        $domain = $website ? $this->extractDomain($website) : null;

        $queries = [
            // Google Maps / Business Profile
            "\"{$name}\"{$location}",
            "{$name}{$location} google maps hours phone",

            // Official site & contact
            "{$name}{$location} official website contact phone email",
            $domain ? "site:{$domain} about owner contact" : null,

            // Owner / leadership
            "{$name}{$location} owner founder managing member",
            "{$name}{$location} president CEO principal",
            "{$name} franchise owner operator{$location}",

            // Directories & reviews
            "{$name}{$location} site:yelp.com",
            "{$name}{$location} site:bbb.org",
            "{$name}{$location} site:yellowpages.com OR site:superpages.com",
            "{$name}{$location} site:manta.com OR site:chamberofcommerce.com",

            // Social media
            "{$name}{$location} site:facebook.com",
            "{$name}{$location} site:instagram.com",
            "{$name}{$location} site:linkedin.com/company OR site:linkedin.com/in",

            // State filings & legal entity
            $cityState ? "{$name} {$cityState} secretary of state business filing LLC" : "{$name} secretary of state LLC filing",
            "{$name}{$location} registered agent incorporator",

            // Payment / POS / field service software
            "{$name}{$location} ServiceTitan Housecall Pro Jobber Square Clover Toast Stripe payment",
            "{$name}{$location} POS system booking software invoicing",
            "{$name}{$location} merchant services credit card processing",

            // Booking portals
            "{$name}{$location} site:booksy.com OR site:schedulicity.com OR site:vcita.com",
            "{$name}{$location} \"book online\" appointment scheduling",

            // Franchise parent
            "{$name} franchise corporate parent company payments",
        ];

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string, query: string, source: string}>
     */
    public function gatherContext(string $businessName, ?string $address, ?string $website, ?int $maxQueries = null): array
    {
        $queries = $this->buildSearchQueries($businessName, $address, $website);
        $maxQueries = $maxQueries ?? config('business_research.max_search_queries', 14);
        $allResults = [];

        foreach (array_slice($queries, 0, $maxQueries) as $query) {
            foreach ($this->searchDuckDuckGo($query, 6) as $result) {
                $key = md5($result['url']);
                if (! isset($allResults[$key])) {
                    $allResults[$key] = array_merge($result, [
                        'query' => $query,
                        'source' => $this->classifySource($result['url']),
                    ]);
                }
            }
            usleep(100000);
        }

        if ($website) {
            $pageText = $this->fetchPageText($website);
            if ($pageText) {
                $allResults['website_'.md5($website)] = [
                    'title' => 'Business website (direct fetch)',
                    'url' => $website,
                    'snippet' => mb_substr($pageText, 0, 4000),
                    'query' => 'website_fetch',
                    'source' => 'website',
                ];
            }
        }

        return array_values($allResults);
    }

    protected function classifySource(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return match (true) {
            str_contains($host, 'google.com') => 'google',
            str_contains($host, 'yelp.com') => 'yelp',
            str_contains($host, 'facebook.com') => 'facebook',
            str_contains($host, 'instagram.com') => 'instagram',
            str_contains($host, 'linkedin.com') => 'linkedin',
            str_contains($host, 'bbb.org') => 'bbb',
            str_contains($host, 'yellowpages') || str_contains($host, 'superpages') => 'directory',
            str_contains($host, 'booksy.com') || str_contains($host, 'schedulicity') => 'booking',
            str_contains($host, 'sos.') || str_contains($host, 'secretary') => 'state_filing',
            default => 'web',
        };
    }

    protected function extractCityState(?string $address): ?string
    {
        if (! $address) {
            return null;
        }

        if (preg_match('/([A-Za-z\s]+),\s*([A-Z]{2})\b/', $address, $match)) {
            return trim($match[1].', '.$match[2]);
        }

        return null;
    }

    protected function fetchPageText(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            $url = 'https://'.$url;
        }

        try {
            $request = Http::timeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; BusinessIntel/1.0)',
            ]);

            if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                return null;
            }

            $text = strip_tags($response->body());
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);

            return trim(mb_substr($text ?? '', 0, 6000));
        } catch (\Throwable) {
            return null;
        }
    }

    protected function extractDomain(string $website): string
    {
        $host = parse_url(
            str_starts_with($website, 'http') ? $website : 'https://'.$website,
            PHP_URL_HOST
        );

        return $host ?: $website;
    }

    /**
     * @param  array<int, array{title: string, url: string, snippet: string, query?: string, source?: string}>  $results
     */
    public function formatContextBlock(array $results): string
    {
        if (empty($results)) {
            return 'No supplemental web snippets collected.';
        }

        $blocks = [];
        foreach (array_slice($results, 0, 40) as $i => $r) {
            $blocks[] = sprintf(
                "[%d] (%s) %s\nURL: %s\nQuery: %s\nSnippet: %s",
                $i + 1,
                $r['source'] ?? 'web',
                $r['title'],
                $r['url'],
                $r['query'] ?? 'n/a',
                mb_substr($r['snippet'], 0, 600),
            );
        }

        return implode("\n\n", $blocks);
    }
}
