<?php

use App\Http\Controllers\PublicDiscoveryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicDiscoveryController::class, 'home'])->name('home');
Route::get('/scholarships', [PublicDiscoveryController::class, 'index'])->name('public.scholarships.index');
Route::get('/scholarships/{slug}', [PublicDiscoveryController::class, 'show'])->where('slug', '[A-Za-z0-9-]+')->name('public.scholarships.show');
Route::get('/universities/{university:slug}', [PublicDiscoveryController::class, 'university'])->name('public.universities.show');
Route::get('/countries/{country:slug}', [PublicDiscoveryController::class, 'country'])->name('public.countries.show');
Route::get('/subjects/{subject:slug}', [PublicDiscoveryController::class, 'subject'])->name('public.subjects.show');
Route::get('/degrees/{degree:slug}', [PublicDiscoveryController::class, 'degree'])->name('public.degrees.show');
Route::view('/{path}', 'app')
    ->where('path', 'login|register|dashboard|profile|admin|unavailable')
    ->name('app.shell');
