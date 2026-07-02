<?php

namespace App\Support;

use Illuminate\Http\Request;

class SimplePaginator
{
    /**
     * @return array{items: array<int, mixed>, meta: array<string, int>}
     */
    public static function slice(array $items, int $page, ?int $perPage = null): array
    {
        $perPage = $perPage ?? (int) config('integrations.communications.list_page_size', 20);
        $perPage = max(1, $perPage);
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'items' => collect($items)->forPage($page, $perPage)->values()->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1),
                'to' => min($page * $perPage, $total),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseQuery
     * @return array{items: array<int, mixed>, pagination: array<string, mixed>}
     */
    public static function paginate(
        array $items,
        Request $request,
        string $routeName,
        array $baseQuery,
        string $pageKey = 'list_page',
        ?string $apiNextPageToken = null,
        string $apiPageTokenKey = 'page_token',
    ): array {
        $perPage = (int) config('integrations.communications.list_page_size', 20);
        $currentPage = max(1, (int) $request->integer($pageKey, 1));
        $sliced = self::slice($items, $currentPage, $perPage);
        $meta = $sliced['meta'];

        $apiOffset = is_numeric($request->get($apiPageTokenKey)) ? (int) $request->get($apiPageTokenKey) : 0;
        $hasApiPrev = $apiOffset > 0;
        $hasApiNext = filled($apiNextPageToken);

        $hasLocalPrev = $meta['page'] > 1;
        $hasLocalNext = $meta['page'] < $meta['last_page'];

        $buildUrl = function (array $overrides) use ($baseQuery, $routeName, $pageKey, $apiPageTokenKey): string {
            $query = array_merge($baseQuery, $overrides);
            foreach ($query as $key => $value) {
                if ($value === null) {
                    unset($query[$key]);
                }
            }

            return route($routeName, array_filter($query, fn ($value) => $value !== null && $value !== ''));
        };

        $prevUrl = null;
        if ($hasLocalPrev) {
            $prevUrl = $buildUrl([$pageKey => $meta['page'] - 1]);
        } elseif ($hasApiPrev) {
            $prevUrl = $buildUrl([
                $apiPageTokenKey => max(0, $apiOffset - $perPage) ?: null,
                $pageKey => null,
            ]);
        }

        $nextUrl = null;
        if ($hasLocalNext) {
            $nextUrl = $buildUrl([$pageKey => $meta['page'] + 1]);
        } elseif ($hasApiNext) {
            $nextUrl = $buildUrl([
                $apiPageTokenKey => $apiNextPageToken,
                $pageKey => null,
            ]);
        }

        return [
            'items' => $sliced['items'],
            'pagination' => [
                ...$meta,
                'has_prev' => $hasLocalPrev || $hasApiPrev,
                'has_next' => $hasLocalNext || $hasApiNext,
                'prev_url' => $prevUrl,
                'next_url' => $nextUrl,
                'api_next' => $hasApiNext && ! $hasLocalNext,
            ],
        ];
    }
}
