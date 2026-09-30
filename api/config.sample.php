<?php
/**
 * Template for api/config.local.php.
 *
 * Copy this file to `config.local.php` and fill in the real Graph client
 * secret. `config.local.php` is git-ignored so secrets never reach the repo.
 * Alternatively set the FACILON_GRAPH_SECRET environment variable.
 */
if (!defined('FACILON_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'graph' => [
        'client_secret' => 'YOUR_GRAPH_CLIENT_SECRET_HERE',
    ],
];
