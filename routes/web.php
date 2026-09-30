<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');
Route::view('/{path}', 'app')
    ->where('path', 'login|register|dashboard|profile|admin|unavailable')
    ->name('app.shell');
