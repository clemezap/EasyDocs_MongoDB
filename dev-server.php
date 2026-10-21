<?php

/*
 * Servidor de desarrollo que imita el despliegue en EC2 (Nginx):
 *   /api/*  -> backend Laravel
 *   resto   -> archivos estáticos de frontend/
 *
 * Uso (desde la raíz del repo):
 *   php -S localhost:8000 -t frontend dev-server.php
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    $publicPath = __DIR__.'/backend/public';
    chdir($publicPath);
    $_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';

    require $publicPath.'/index.php';

    return true;
}

// Archivos estáticos del frontend
return false;
