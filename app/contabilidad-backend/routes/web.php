<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Sin caché: mientras se edita la landing, el navegador siempre pide la
    // versión nueva en lugar de mostrar la que tenía guardada.
    return response()
        ->view('landing')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
});
