<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use MongoDB\Database;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reemplaza a "php artisan db:show" de MySQL.
// Solo usa ping, buildInfo y countDocuments: bastan los permisos del usuario de la app.
Artisan::command('mongo:estado', function (Database $base) {
    try {
        $base->command(['ping' => 1]);
        $version = $base->command(['buildInfo' => 1])->toArray()[0]->version;
    } catch (Throwable $e) {
        $this->error('No se pudo conectar a MongoDB: '.$e->getMessage());
        $this->line('Revisa MONGODB_URI en el .env y que tu IP esté en Network Access de Atlas.');

        return 1;
    }

    $this->info("Conectado a MongoDB {$version}, base \"{$base->getDatabaseName()}\".");

    $todoBien = true;

    foreach (['usuarios', 'carpetas', 'archivos'] as $nombre) {
        try {
            $this->line(sprintf('  %-9s %d documentos', $nombre, $base->selectCollection($nombre)->countDocuments()));
        } catch (Throwable $e) {
            $this->error(sprintf('  %-9s %s', $nombre, $e->getMessage()));
            $todoBien = false;
        }
    }

    if (! $todoBien) {
        $this->line('Revisa que el rol del usuario de la app tenga find, insert, update y remove sobre la base.');
    }

    return $todoBien ? 0 : 1;
})->purpose('Comprueba la conexión a MongoDB Atlas');
