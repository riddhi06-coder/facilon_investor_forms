<?php
/**
 * TEMPORARY diagnostic — open in a browser, read the output, then DELETE this file.
 * URL: https://mbihosting.in/facilonservices/api/_diag.php
 */
define('FACILON_APP', true);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version : " . PHP_VERSION . "\n";
echo "arrow fns   : " . (version_compare(PHP_VERSION, '7.4.0', '>=') ? 'supported (>=7.4)' : 'NOT SUPPORTED (<7.4) — this breaks the API') . "\n\n";

$exts = array('curl', 'pdo_mysql', 'gd', 'openssl', 'mbstring', 'json');
foreach ($exts as $e) {
    echo str_pad($e, 12) . ": " . (extension_loaded($e) ? 'yes' : 'NO - MISSING') . "\n";
}
echo str_pad('gd webp', 12) . ": " . (function_exists('imagecreatefromwebp') ? 'yes' : 'no (logo falls back to text)') . "\n\n";

$cfgFile = __DIR__ . '/config.php';
echo "config.php  : " . (is_file($cfgFile) ? 'found' : 'MISSING') . "\n";
echo "config.local: " . (is_file(__DIR__ . '/config.local.php') ? 'found' : 'MISSING') . "\n";

$cfg = require $cfgFile;
echo "secret len  : " . strlen($cfg['graph']['client_secret']) . " (should be 40)\n";
$d = $cfg['db'];
echo "db target   : {$d['user']}@{$d['host']} / {$d['name']}\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}",
        $d['user'], $d['pass'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    echo "DB connect  : OK\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS _diag_test (id INT)");
    echo "CREATE TABLE: OK (user can create tables)\n";
    $pdo->exec("DROP TABLE _diag_test");
    echo "DROP TABLE  : OK\n";
} catch (Throwable $e) {
    echo "DB ERROR    : " . $e->getMessage() . "\n";
}
