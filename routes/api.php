<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\EligibilityController;
use App\Http\Controllers\Api\V1\Admin\ScholarshipController;
use App\Http\Controllers\Api\V1\AdminFoundationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.v1.health');

Route::prefix('catalog')->name('api.v1.catalog.')->group(function () {
    Route::get('/regions', [CatalogController::class, 'regions'])->name('regions.index');
    Route::get('/countries', [CatalogController::class, 'countries'])->name('countries.index');
    Route::get('/universities', [CatalogController::class, 'universities'])->name('universities.index');
    Route::get('/subjects', [CatalogController::class, 'subjects'])->name('subjects.index');
    Route::get('/degrees', [CatalogController::class, 'degrees'])->name('degrees.index');
});

Route::prefix('auth')->name('api.v1.auth.')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
    Route::get('/users/{user}', [AccountController::class, 'show'])->name('api.v1.users.show');
    Route::get('/admin/foundation', AdminFoundationController::class)->name('api.v1.admin.foundation');

    Route::middleware('can:admin.access')->prefix('admin')->name('api.v1.admin.')->group(function () {
        Route::post('/catalog/regions', [CatalogController::class, 'storeRegion'])->name('catalog.regions.store');
        Route::patch('/catalog/regions/{region}', [CatalogController::class, 'updateRegion'])->name('catalog.regions.update');
        Route::post('/catalog/countries', [CatalogController::class, 'storeCountry'])->name('catalog.countries.store');
        Route::patch('/catalog/countries/{country}', [CatalogController::class, 'updateCountry'])->name('catalog.countries.update');
        Route::post('/catalog/universities', [CatalogController::class, 'storeUniversity'])->name('catalog.universities.store');
        Route::patch('/catalog/universities/{university}', [CatalogController::class, 'updateUniversity'])->name('catalog.universities.update');
        Route::post('/catalog/subjects', [CatalogController::class, 'storeSubject'])->name('catalog.subjects.store');
        Route::patch('/catalog/subjects/{subject}', [CatalogController::class, 'updateSubject'])->name('catalog.subjects.update');
        Route::post('/catalog/degrees', [CatalogController::class, 'storeDegree'])->name('catalog.degrees.store');
        Route::patch('/catalog/degrees/{degree}', [CatalogController::class, 'updateDegree'])->name('catalog.degrees.update');

        Route::get('/scholarships', [ScholarshipController::class, 'index'])->name('scholarships.index');
        Route::post('/scholarships', [ScholarshipController::class, 'store'])->name('scholarships.store');
        Route::get('/scholarships/{scholarship}', [ScholarshipController::class, 'show'])->name('scholarships.show');
        Route::patch('/scholarships/{scholarship}', [ScholarshipController::class, 'update'])->name('scholarships.update');
        Route::get('/scholarships/{scholarship}/cycles', [ScholarshipController::class, 'cycles'])->name('scholarships.cycles.index');
        Route::post('/scholarships/{scholarship}/cycles', [ScholarshipController::class, 'storeCycle'])->name('scholarships.cycles.store');
        Route::get('/cycles/{cycle}', [ScholarshipController::class, 'showCycle'])->name('cycles.show');
        Route::patch('/cycles/{cycle}', [ScholarshipController::class, 'updateCycle'])->name('cycles.update');
        Route::get('/cycles/{cycle}/versions', [ScholarshipController::class, 'versions'])->name('cycles.versions.index');
        Route::get('/scholarships/{scholarship}/sources', [ScholarshipController::class, 'sources'])->name('scholarships.sources.index');
        Route::post('/scholarships/{scholarship}/sources', [ScholarshipController::class, 'storeSource'])->name('scholarships.sources.store');
        Route::get('/cycles/{cycle}/funding', [ScholarshipController::class, 'funding'])->name('cycles.funding.show');
        Route::put('/cycles/{cycle}/funding', [ScholarshipController::class, 'saveFunding'])->name('cycles.funding.update');
        Route::get('/cycles/{cycle}/eligibility', [EligibilityController::class, 'index'])->name('cycles.eligibility.index');
        Route::post('/cycles/{cycle}/eligibility', [EligibilityController::class, 'store'])->name('cycles.eligibility.store');
        Route::patch('/eligibility/{rule}', [EligibilityController::class, 'update'])->name('eligibility.update');
        Route::delete('/eligibility/{rule}', [EligibilityController::class, 'destroy'])->name('eligibility.destroy');
    });
});
