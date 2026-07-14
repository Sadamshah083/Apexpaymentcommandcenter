<?php



namespace App\Services\Crm;



class CrmColumnMapper

{

    /**

     * Fields are matched in this order (most specific first).

     *

     * @var array<string, array<int, string>>

     */

    protected array $fieldPatterns = [

        'business_name' => [

            'business name',

            'businessname',

            'company name',

            'company',

            'organization name',

            'organization',

            'legal name',

            'trade name',

            'doing business as',

            'dba',

            'account name',

            'client name',

            'customer name',

            'vendor name',

            'store name',

            'shop name',

            'merchant name',

            'location name',

            'business',

            'bus name',

            'comp name',

            'organisation',

            'firm name',

            'firm',

            'establishment',

            'name',

        ],

        'full_address' => [

            'full address',

            'complete address',

            'full location',

            'business address',

            'store address',

            'location address',

            'mailing address full',

            'address full',

            'street city state',

            'address city state zip',

            'formatted address',

            'location',

        ],

        'address' => [

            'street address',

            'address line 1',

            'address line1',

            'address1',

            'address 1',

            'street',

            'physical address',

            'mailing address',

            'addr',

            'address',

        ],

        'address_line_2' => [

            'address line 2',

            'address line2',

            'address2',

            'address 2',

            'suite',

            'unit',

            'apt',

            'apartment',

            'floor',

        ],

        'city' => ['city', 'town', 'municipality', 'locality', 'address city', 'addr city'],

        'state' => ['state', 'province', 'state code', 'state/province', 'address state', 'st'],

        'zip_code' => ['zip code', 'zipcode', 'zip', 'postal code', 'postcode', 'postal', 'zip/postal'],

        'country' => ['country', 'nation', 'country code'],

        'website' => ['website url', 'website', 'web site', 'web address', 'homepage', 'domain', 'url'],

        'input_phone' => [

            'contact number',

            'contact no',

            'phone number',

            'telephone number',

            'contact phone',

            'business phone',

            'main phone',

            'phone',

            'telephone',

            'tel',

            'mobile',

            'cell',

            'fax',

        ],

        'input_email' => [

            'email address',

            'e mail',

            'e-mail',

            'contact email',

            'business email',

            'email',

        ],

    ];



    /** @var array<int, string> */

    protected array $fieldPriority = [

        'business_name',

        'full_address',

        'address',

        'address_line_2',

        'city',

        'state',

        'zip_code',

        'country',

        'website',

        'input_phone',

        'input_email',

    ];



    /**

     * Prefer these exact normalized headers when present (fixes scraper exports).

     *

     * @var array<string, array<int, string>>

     */

    protected array $exactPreferred = [

        'business_name' => ['company name', 'business name', 'company', 'name'],

        'address' => ['address', 'street address', 'street'],

        'city' => ['city'],

        'state' => ['state'],

        'zip_code' => ['zip code', 'zipcode', 'zip', 'postal code'],

        'website' => ['website', 'website url', 'url'],

        'input_phone' => ['phone number', 'phone', 'telephone'],

        'input_email' => ['email', 'email address'],

    ];



    /** @var array<int, string> */

    protected array $genericHeaders = [

        'name', 'title', 'id', 'no', 'number', 'num', 'row', 'index', 'notes', 'note', 'comment', 'comments',

    ];



    /**

     * @param  array<int, string>  $headers

     * @return array<string, string|null>

     */

    public function map(array $headers): array

    {

        $mapping = array_fill_keys($this->fieldPriority, null);

        $used = [];



        foreach ($this->fieldPriority as $field) {

            $bestIndex = null;

            $bestScore = 0;

            $threshold = $this->scoreThreshold($field);



            foreach ($headers as $index => $header) {

                if (isset($used[$index]) || trim($header) === '') {

                    continue;

                }



                $score = $this->scoreHeader($header, $this->fieldPatterns[$field], $field);



                if ($score > $bestScore && $score >= $threshold) {

                    $bestScore = $score;

                    $bestIndex = $index;

                }

            }



            if ($bestIndex !== null) {

                $mapping[$field] = $headers[$bestIndex];

                $used[$bestIndex] = true;

            }

        }



        $mapping = $this->applyExactPreferredHeaders($headers, $mapping, $used);



        if (! $mapping['business_name']) {

            $mapping['business_name'] = $this->guessBusinessNameColumn($headers, $used);

        }



        if (! $mapping['full_address'] && ! $mapping['address'] && ! $mapping['city']) {

            foreach ($headers as $index => $header) {

                if (isset($used[$index])) {

                    continue;

                }



                $normalized = $this->normalize($header);

                if (in_array($normalized, ['location', 'loc', 'place', 'area'], true)) {

                    $mapping['full_address'] = $header;

                    $used[$index] = true;

                    break;

                }

            }

        }



        return $mapping;

    }



    /**

     * @param  array<int, string>  $headers

     * @param  array<string, string|null>  $mapping

     * @param  array<int, bool>  $used

     * @return array<string, string|null>

     */

    protected function applyExactPreferredHeaders(array $headers, array $mapping, array &$used): array

    {

        $indexByNormalized = [];

        foreach ($headers as $index => $header) {

            $indexByNormalized[$this->normalize($header)] = $index;

        }



        foreach ($this->exactPreferred as $field => $preferred) {

            foreach ($preferred as $normalized) {

                if (! isset($indexByNormalized[$normalized])) {

                    continue;

                }



                $index = $indexByNormalized[$normalized];

                $header = $headers[$index];



                if (($mapping[$field] ?? null) === $header) {

                    break;

                }



                if ($mapping[$field]) {

                    $oldHeader = $mapping[$field];

                    foreach ($headers as $oldIndex => $h) {

                        if ($h === $oldHeader) {

                            unset($used[$oldIndex]);

                            break;

                        }

                    }

                }



                $mapping[$field] = $header;

                $used[$index] = true;

                break;

            }

        }



        return $mapping;

    }



    /**

     * @param  array<int, string>  $headers

     * @return array<string, string>

     */

    public function unmappedHeaders(array $headers, array $mapping): array

    {

        $mapped = array_filter(array_values($mapping));

        $extra = [];



        foreach ($headers as $header) {

            if ($header !== '' && ! in_array($header, $mapped, true)) {

                $extra[$header] = $header;

            }

        }



        return $extra;

    }



    /**

     * @param  array<int, string>  $headers

     * @return array<string, string|null>

     */

    public function describeMapping(array $headers): array

    {

        $mapping = $this->map($headers);

        $labels = [

            'business_name' => 'Business / Company Name',

            'full_address' => 'Full Address (one column)',

            'address' => 'Street Address',

            'address_line_2' => 'Address Line 2 / Suite',

            'city' => 'City',

            'state' => 'State',

            'zip_code' => 'ZIP / Postal',

            'country' => 'Country',

            'website' => 'Website',

            'input_phone' => 'Phone',

            'input_email' => 'Email',

        ];



        $described = [];

        foreach ($labels as $field => $label) {

            if (! empty($mapping[$field])) {

                $described[$label] = $mapping[$field];

            }

        }



        return $described;

    }



    protected function scoreThreshold(string $field): int

    {

        return match ($field) {

            'business_name' => 12,

            'city', 'state', 'zip_code' => 90,

            default => 8,

        };

    }



    /**

     * @param  array<int, string>  $patterns

     */

    protected function scoreHeader(string $header, array $patterns, string $field): int

    {

        $normalized = $this->normalize($header);



        if ($normalized === '' || $this->disqualifiesHeader($normalized, $field)) {

            return 0;

        }



        $best = 0;



        foreach ($patterns as $i => $pattern) {

            $priorityBoost = (count($patterns) - $i) * 3;



            if ($normalized === $pattern) {

                return 100 + $priorityBoost;

            }



            if (str_starts_with($normalized, $pattern.' ') || str_ends_with($normalized, ' '.$pattern)) {

                $best = max($best, 85 + $priorityBoost);

                continue;

            }



            $patternWords = explode(' ', $pattern);

            $headerWords = explode(' ', $normalized);



            if (count($patternWords) > 1 && count(array_intersect($patternWords, $headerWords)) === count($patternWords)) {

                $best = max($best, 75 + $priorityBoost);

                continue;

            }



            if ($this->containsPattern($normalized, $pattern)) {

                $best = max($best, 55 + $priorityBoost);

            }

        }



        if ($field === 'business_name' && in_array($normalized, $this->genericHeaders, true)) {

            $best = min($best, 15);

        }



        if ($field === 'address' && preg_match('/\b(2|two|line 2|line2|suite|unit|apt)\b/', $normalized)) {

            $best = 0;

        }



        if ($field === 'website' && (str_contains($normalized, 'email') || str_contains($normalized, 'phone'))) {

            $best = 0;

        }



        if (in_array($field, ['city', 'state', 'zip_code'], true) && $best < 100) {

            $best = 0;

        }



        return $best;

    }



    protected function containsPattern(string $normalized, string $pattern): bool

    {

        if (strlen($pattern) <= 3 || str_contains($pattern, ' ')) {

            return (bool) preg_match('/\b'.preg_quote($pattern, '/').'\b/i', $normalized);

        }



        if ($normalized === $pattern) {

            return true;

        }



        if (str_starts_with($normalized, $pattern.' ') || str_ends_with($normalized, ' '.$pattern)) {

            return true;

        }



        return (bool) preg_match('/\b'.preg_quote($pattern, '/').'\b/i', $normalized);

    }



    protected function disqualifiesHeader(string $normalized, string $field): bool

    {

        $noise = [

            'search', 'query', 'keyword', 'filter', 'sort', 'rank', 'score', 'rating', 'review',

            'shopping', 'category', 'type', 'tag', 'label', 'status', 'flag', 'open', 'close',

            'hour', 'minute', 'count', 'total', 'result', 'place', 'store', 'shop', 'photo',

            'image', 'link', 'url count', 'latitude', 'longitude', 'lat', 'lng', 'geo',

        ];



        if (in_array($field, ['city', 'state', 'zip_code'], true)) {

            foreach ($noise as $word) {

                if ($normalized === $word) {

                    return true;

                }



                if (str_starts_with($normalized, $word.' ') || str_contains($normalized, ' '.$word.' ')) {

                    return true;

                }

            }



            if ($field === 'city' && (str_starts_with($normalized, 'search ') || str_contains($normalized, ' search '))) {

                return true;

            }



            if ($field === 'state' && (

                str_contains($normalized, 'shopping')

                || str_contains($normalized, 'store ')

                || str_starts_with($normalized, 'store ')

                || preg_match('/\b(store|shop|place type|category)\b/', $normalized)

            )) {

                return true;

            }

        }



        if ($field === 'website' && preg_match('/\b(email|mail|phone|review|rating)\b/', $normalized)) {

            return true;

        }



        if ($field === 'input_email' && $normalized === 'mail') {

            return true;

        }



        return false;

    }



    /**

     * @param  array<int, string>  $headers

     * @param  array<int, bool>  $used

     */

    protected function guessBusinessNameColumn(array $headers, array $used): ?string

    {

        foreach ($headers as $index => $header) {

            if (isset($used[$index])) {

                continue;

            }



            $normalized = $this->normalize($header);

            if (in_array($normalized, ['company name', 'company', 'business name', 'business', 'name', 'organization', 'account'], true)) {

                return $header;

            }

        }



        foreach ($headers as $index => $header) {

            if (! isset($used[$index]) && trim($header) !== '') {

                return $header;

            }

        }



        return $headers[0] ?? null;

    }



    protected function normalize(string $header): string

    {

        $header = strtolower(trim($header));

        $header = str_replace(['_', '-', '/', '\\'], ' ', $header);

        $header = preg_replace('/[^a-z0-9\s]/', ' ', $header);

        $header = preg_replace('/\s+/', ' ', $header);



        return trim($header);

    }

}


