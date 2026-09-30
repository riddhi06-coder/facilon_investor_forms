<?php
/**
 * Central configuration. Server-side only.
 * Direct browser access is blocked by the guard below and by the FACILON_APP
 * constant that entry scripts define before including this file.
 *
 * SECURITY: the Graph client secret lives here, never in any client/JS file.
 * Rotate the secret in Azure if it has been shared over an insecure channel.
 */
if (!defined('FACILON_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/*
 * NOTE: no secrets in this committed file.
 * The Graph client secret is loaded from api/config.local.php (git-ignored)
 * or the FACILON_GRAPH_SECRET environment variable. See api/config.sample.php.
 */
$config = [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'facilon_investor_forms',
        'user'    => 'root',
        'pass'    => '',            // default XAMPP MySQL password is empty
        'charset' => 'utf8mb4',
    ],

    'graph' => [
        'tenant'        => '82bfe941-8424-460e-b024-540394d1a92e',
        'client_id'     => '3eb83171-58a2-4841-a565-b9f8d4a280e8',
        'client_secret' => getenv('FACILON_GRAPH_SECRET') ?: '',
        'from'          => 'no-reply@facilonservices.com',
        'base'          => 'https://graph.microsoft.com/v1.0',
        'scope'         => 'https://graph.microsoft.com/.default',
    ],

    // Recipient of the internal notification on every submission.
    'admin_email' => 'riddhi@matrixbricks.com',

    // Public-facing brand name used in emails.
    'brand' => 'Facilon Services Private Limited',
];

// Merge local overrides (secrets) if present — never committed to git.
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

return $config;
