<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'mensaje' => 'API de EasyDocs. Los endpoints están bajo /api.',
    ]);
});
