<?php

declare(strict_types=1);

return [
    // Défaut 'null' : les environnements sans Meilisearch (tests, CI) n'indexent
    // rien et les recherches renvoient vide, sans erreur. Prod : SCOUT_DRIVER=meilisearch.
    'driver' => env('SCOUT_DRIVER', 'null'),

    'prefix' => env('SCOUT_PREFIX', ''),

    // Indexation synchrone (MVP) : le volume par mutation est faible.
    'queue' => env('SCOUT_QUEUE', false),

    'after_commit' => true,

    'chunk' => ['searchable' => 500, 'unsearchable' => 500],

    'soft_delete' => false,

    'identify' => false,

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        // tenant_id filtrable PARTOUT : chaque recherche est scopée tenant.
        'index-settings' => [
            'shipments' => [
                'filterableAttributes' => ['tenant_id'],
                'searchableAttributes' => ['reference', 'client_name', 'origin', 'destination'],
            ],
            'parties' => [
                'filterableAttributes' => ['tenant_id'],
                'searchableAttributes' => ['code', 'name'],
            ],
            'containers' => [
                'filterableAttributes' => ['tenant_id'],
                'searchableAttributes' => ['number'],
            ],
            'bookings' => [
                'filterableAttributes' => ['tenant_id'],
                'searchableAttributes' => ['booking_number'],
            ],
            'invoices' => [
                'filterableAttributes' => ['tenant_id'],
                'searchableAttributes' => ['number'],
            ],
        ],
    ],
];
