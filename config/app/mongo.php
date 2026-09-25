<?php

declare(strict_types=1);

return [
    'uri'         => env('APP_MONGO_URI', 'mongodb://mongodb:27017'),
    'database'    => env('APP_MONGO_DB', 'ozon'),
    'uri_options' => ['serverSelectionTimeoutMS' => 3000],
];
