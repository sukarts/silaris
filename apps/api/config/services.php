<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shipsgo' => [
        'api_key' => env('SHIPSGO_API_KEY'),
        'base_url' => env('SHIPSGO_BASE_URL', 'https://api.shipsgo.com/v2'),
        // Secret de compte servant à valider la signature des webhooks.
        'webhook_secret' => env('SHIPSGO_WEBHOOK_SECRET'),
    ],

    // Facture Normalisée Électronique — DGI Côte d'Ivoire. La clé d'API et les
    // identifiants (NCC, point de vente, établissement) sont propres à chaque
    // société transitaire, stockés sur la société ; seule l'URL de la plateforme
    // dépend de l'environnement. 'test' vise le bac à sable public de la DGI ;
    // 'production' attend l'URL délivrée à l'enrôlement.
    'fne' => [
        'env' => env('FNE_ENV', 'test'),
        'base_url' => [
            'test' => env('FNE_TEST_URL', 'http://54.247.95.108/ws'),
            'production' => env('FNE_PRODUCTION_URL', ''),
        ],
        'timeout' => (int) env('FNE_TIMEOUT', 20),
    ],

];
