<?php
$path = 'C:/Wamp/bin/php/php8.2.18/extras/ssl/cacert.pem';

echo '<pre>';
var_dump([
    'path'       => $path,
    'exists'     => file_exists($path),
    'is_file'    => is_file($path),
    'readable'   => is_readable($path),
    'size'       => file_exists($path) ? filesize($path) : null,
    'curl.cainfo' => ini_get('curl.cainfo'),
    'openssl.cafile' => ini_get('openssl.cafile'),
]);
?>