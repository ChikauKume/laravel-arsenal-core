<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LAC Default Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for Laravel Arsenal Core (LAC) package.
    |
    */

    'defaults' => [
        // Default namespace for models
        'model_namespace' => 'App\\Models',
        
        // Default database connection
        'database_connection' => env('DB_CONNECTION', 'mysql'),
        
        // Enable soft deletes by default
        'soft_deletes' => false,
    ],
    
];