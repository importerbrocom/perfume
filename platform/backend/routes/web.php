<?php

use App\Http\Controllers\Shop\StoreController;
use Illuminate\Support\Facades\Route;

// Apple Pay domain verification (file content is pasted in Admin > Settings).
Route::get('.well-known/apple-developer-merchantid-domain-association', [StoreController::class, 'applePay']);

// Staging gate form posts here (StagingGate handles the key and sets the cookie).
Route::post('gate', fn () => redirect('/'));

// Everything else is the React website / admin panel.
Route::get('{any?}', function () {
    $index = config('shop.spa_index');
    abort_unless(is_file($index), 503, 'The website files are not installed yet.');

    return response()->file($index, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-cache']);
})->where('any', '^(?!api/|storage/|build/|assets/).*$');
