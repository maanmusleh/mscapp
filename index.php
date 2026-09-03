<?php
// /home/site/wwwroot/index.php
// Hands the request off to the real front controller in public/

// Serve existing static files in public/ directly (css, js, images).
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . '/public' . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // let the web server handle it
}

require_once __DIR__ . '/public/index.php';
?>