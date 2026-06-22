<?php
/**
 * Windows-compatible configuration for CakePHP app
 * This configuration sends logs directly to 10.0.2.30
 */

return [
    'debug' => true,
    
    'Security' => [
        'salt' => env('SECURITY_SALT', '__SALT_WILL_BE_GENERATED__'),
    ],

    'Datasources' => [
        'default' => [
            'className' => 'Cake\Database\Connection',
            'driver' => 'Cake\Database\Driver\Mysql',
            'persistent' => false,
            'host' => '10.0.2.30',  // Central MySQL server
            'port' => '3306',
            'username' => 'cakeuser',
            'password' => 'cakepass',
            'database' => 'sample_logging_db',
            'timezone' => 'UTC',
            'flags' => [],
            'cacheMetadata' => true,
            'log' => false,
            'quoteIdentifiers' => false,
        ],
    ],

    /**
     * Logging configuration for Windows
     * Sends directly to Fluent Bit HTTP endpoint
     */
    'Log' => [
        'debug' => [
            'className' => 'App\Log\Engine\DirectHttpLog',
            'server' => '10.0.2.30',
            'port' => '8888',
            'levels' => ['notice', 'info', 'debug'],
            'scopes' => false,
            'fallback' => true,  // Also save locally if HTTP fails
        ],
        'error' => [
            'className' => 'App\Log\Engine\DirectHttpLog',
            'server' => '10.0.2.30',
            'port' => '8888',
            'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
            'scopes' => false,
            'fallback' => true,
        ],
        // Local file backup
        'local' => [
            'className' => 'Cake\Log\Engine\FileLog',
            'path' => LOGS,
            'file' => 'local_backup',
            'levels' => [],
            'scopes' => false,
        ],
    ],

    /**
     * Central logging server configuration
     */
    'CentralLogging' => [
        'enabled' => true,
        'server' => '10.0.2.30',
        'http_port' => 8888,
        'dashboard_url' => 'http://10.0.2.30:5601',
    ],
];