<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Maps Lead Scraper
    |--------------------------------------------------------------------------
    |
    | Playwright Google Maps scraper (tools/google-maps-scraper).
    | No Google Places API billing — free unlimited usage within ToS / robots limits.
    | Small businesses only via --individual-only. Excel exports by phone area code.
    |
    */
    'enabled' => filter_var(env('MAPS_SCRAPER_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),

    'python' => env('MAPS_SCRAPER_PYTHON', 'python'),

    'path' => env('MAPS_SCRAPER_PATH', base_path('tools/google-maps-scraper')),

    'headless' => filter_var(env('MAPS_SCRAPER_HEADLESS', 'true'), FILTER_VALIDATE_BOOLEAN),

    'chrome_path' => env('MAPS_SCRAPER_CHROME_PATH'),

    'timeout_seconds' => (int) env('MAPS_SCRAPER_TIMEOUT', 28800),

    // 0 = unlimited (scrape all listings Maps returns for the search).
    'default_per_city' => 0,

    'default_total' => 0,

    'storage_disk' => 'local',

    'storage_dir' => 'maps-scraper',

    /*
    | Priority payment-processing categories shown on the dashboard Start table.
    | Clicking Start runs a quick Maps search for "{category} in {default_city}, {default_state}".
    */
    'default_state' => env('MAPS_SCRAPER_DEFAULT_STATE', 'Georgia'),

    'default_city' => env('MAPS_SCRAPER_DEFAULT_CITY', 'Atlanta'),

    /*
    | Major cities used when Wikipedia city cache is not available yet.
    */
    'fallback_cities' => [
        'Georgia' => ['Atlanta', 'Augusta', 'Columbus', 'Macon', 'Savannah', 'Athens', 'Sandy Springs', 'Roswell', 'Johns Creek', 'Albany', 'Warner Robins', 'Alpharetta', 'Marietta', 'Valdosta', 'Smyrna'],
        'Alabama' => ['Birmingham', 'Montgomery', 'Huntsville', 'Mobile', 'Tuscaloosa', 'Hoover', 'Dothan', 'Auburn', 'Decatur', 'Madison'],
        'Florida' => ['Jacksonville', 'Miami', 'Tampa', 'Orlando', 'St. Petersburg', 'Hialeah', 'Tallahassee', 'Fort Lauderdale', 'Port St. Lucie', 'Cape Coral'],
        'Texas' => ['Houston', 'San Antonio', 'Dallas', 'Austin', 'Fort Worth', 'El Paso', 'Arlington', 'Corpus Christi', 'Plano', 'Lubbock'],
        'California' => ['Los Angeles', 'San Diego', 'San Jose', 'San Francisco', 'Fresno', 'Sacramento', 'Long Beach', 'Oakland', 'Bakersfield', 'Anaheim'],
        'New York' => ['New York', 'Buffalo', 'Rochester', 'Yonkers', 'Syracuse', 'Albany', 'New Rochelle', 'Mount Vernon', 'Schenectady', 'Utica'],
        'North Carolina' => ['Charlotte', 'Raleigh', 'Greensboro', 'Durham', 'Winston-Salem', 'Fayetteville', 'Cary', 'Wilmington', 'High Point', 'Concord'],
        'Illinois' => ['Chicago', 'Aurora', 'Naperville', 'Joliet', 'Rockford', 'Springfield', 'Elgin', 'Peoria', 'Champaign', 'Waukegan'],
        'Ohio' => ['Columbus', 'Cleveland', 'Cincinnati', 'Toledo', 'Akron', 'Dayton', 'Parma', 'Canton', 'Youngstown', 'Lorain'],
        'Pennsylvania' => ['Philadelphia', 'Pittsburgh', 'Allentown', 'Reading', 'Scranton', 'Erie', 'Bethlehem', 'Lancaster', 'Harrisburg', 'York'],
    ],

    'priority_categories' => [
        'Auto Repair Shop',
        'Tire Shop',
        'Oil Change Shop',
        'Auto Body Shop',
        'Collision Repair Center',
        'Hair Salon',
        'Beauty Salon',
        'Barbershop',
        'Nail Salon',
        'Med Spa',
        'Restaurant',
        'Food Truck',
        'Convenience Store',
        'Smoke Shop',
        'Liquor Store',
        'Jewelry Store',
        'Flower Shop',
        'Dry Cleaner',
        'Laundromat',
        'Car Wash',
        'Mobile Mechanic',
        'HVAC Company',
        'Plumbing Company',
        'Electrician',
        'Roofing Company',
        'Landscaping Company',
        'General Contractor',
        'Locksmith',
    ],
];
