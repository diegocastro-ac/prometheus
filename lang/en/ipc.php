<?php

return [

    'basis' => [
        'national' => 'National total (Art. 20)',
        'city' => 'Property city',
    ],

    'strategies' => [
        'annual_variation' => 'December annual variation (what Article 20 requires)',
        'index_ratio' => 'Ratio between CPI indices',
        'informative_marker' => 'informative, not the legal cap',
        'frozen' => 'Explicitly chosen CPI',
    ],

    'source' => [
        'label' => 'Source',
        'dane' => 'DANE',
        'synced_at' => 'Synced on',
        'never_synced' => 'Never synced with DANE',
    ],

    'filters' => [
        'all' => 'All',
    ],

    'navigation' => [
        'group' => 'Information',
        'label' => 'CPI series (DANE)',
    ],

    'model' => [
        'singular' => 'CPI record',
        'plural' => 'CPI records',
    ],

    'table' => [
        'year' => 'Year',
        'variation_year_hint' => 'This is the figure Article 20 uses',
        'geography' => 'Geography',
        'period' => 'Period',
        'index' => 'Index',
        'variation_monthly' => 'MoM change',
        'variation_yoy' => 'YoY change',
        'variation_year' => 'YTD change',
        'synced_at' => 'Synced',
        'source_url' => 'View document',
        'source' => 'Source',
        'empty' => 'No CPI data has been loaded yet.',
    ],

    'messages' => [
        'no_data' => 'There is no CPI data loaded in the system.',
        'no_cities' => 'No cities with CPI have been loaded in the system.',
        'last_sync' => 'Last load: :date',
        'never' => 'no data',
        'stale' => 'Series out of date',
        'stale_title' => 'The CPI series is out of date',
        'stale_body' => 'The last load from DANE is older than the date shown. Adjustment ceilings are calculated from that series, so a recent CPI may not yet be in the system.',
    ],

];
