<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use MongoDB\Client;
use MongoDB\Database;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Un solo cliente de MongoDB por petición: abre la conexión a Atlas
        // la primera vez que se usa y la reutiliza.
        $this->app->singleton(Client::class, function () {
            $uri = config('easydocs.mongodb.uri');

            if (! $uri) {
                throw new RuntimeException('Falta MONGODB_URI en el .env.');
            }

            return new Client(
                $uri,
                // Si Atlas no responde (IP no autorizada, sin red), fallar en 5 s y no en 30
                ['serverSelectionTimeoutMS' => 5000],
                // Documentos como stdClass ($archivo->nombre), igual que DB::selectOne,
                // y arreglos de BSON como arreglos de PHP
                ['typeMap' => ['root' => 'object', 'document' => 'object', 'array' => 'array']]
            );
        });

        // Los controladores reciben la base ya seleccionada
        $this->app->singleton(Database::class, function ($app) {
            return $app->make(Client::class)->selectDatabase(config('easydocs.mongodb.base'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
