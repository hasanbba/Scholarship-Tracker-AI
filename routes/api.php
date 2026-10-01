<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\CrawlerAdminController;
use App\Http\Controllers\Api\V1\Admin\DataQualityController;
use App\Http\Controllers\Api\V1\Admin\EligibilityController;
use App\Http\Controllers\Api\V1\Admin\ScholarshipController;
use App\Http\Controllers\Api\V1\Admin\ScholarshipCyclePublicationController;
use App\Http\Controllers\Api\V1\Admin\ScholarshipVersionWorkflowController;
use App\Http\Controllers\Api\V1\AdminFoundationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CrawlerWorkerController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PublicScholarshipController;
use App\Http\Middleware\AuthenticateCrawlerWorker;
use App\Http\Middleware\AuthenticateUserSession;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.v1.health');
Route::get('/scholarships', [PublicScholarshipController::class, 'index'])->name('api.v1.scholarships.index');
Route::get('/scholarships/{slug}', [PublicScholarshipController::class, 'show'])->name('api.v1.scholarships.show');

Route::prefix('crawler')->name('api.v1.crawler.')->group(function () {
    Route::post('/workers/activate', [CrawlerWorkerController::class, 'activate'])->middleware('throttle:crawler-activation')->name('workers.activate');
    Route::middleware([AuthenticateCrawlerWorker::class.':workers.me', 'throttle:crawler-worker'])->get('/workers/me', [CrawlerWorkerController::class, 'me'])->name('workers.me');
    Route::middleware([AuthenticateCrawlerWorker::class.':jobs.claim', 'throttle:crawler-worker'])->post('/jobs/claim', [CrawlerWorkerController::class, 'claim'])->name('jobs.claim');
    Route::middleware([AuthenticateCrawlerWorker::class.':jobs.heartbeat', 'throttle:crawler-worker'])->post('/jobs/{job}/attempts/{attempt}/heartbeat', [CrawlerWorkerController::class, 'heartbeat'])->name('jobs.heartbeat');
    Route::middleware([AuthenticateCrawlerWorker::class.':jobs.results', 'throttle:crawler-worker'])->post('/jobs/{job}/attempts/{attempt}/artifacts', [CrawlerWorkerController::class, 'uploadArtifact'])->name('jobs.artifacts.store');
    Route::middleware([AuthenticateCrawlerWorker::class.':jobs.results', 'throttle:crawler-worker'])->post('/jobs/{job}/attempts/{attempt}/results', [CrawlerWorkerController::class, 'submitResult'])->name('jobs.results.store');
});

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

Route::middleware(AuthenticateUserSession::class)->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
    Route::get('/users/{user}', [AccountController::class, 'show'])->name('api.v1.users.show');
    Route::get('/admin/foundation', AdminFoundationController::class)->name('api.v1.admin.foundation');

    Route::middleware('can:admin.access')->prefix('admin')->name('api.v1.admin.')->group(function () {
        Route::post('/crawler/activation-codes', [CrawlerAdminController::class, 'issueActivation'])->middleware('can:crawler.workers.manage')->name('crawler.activation-codes.store');
        Route::get('/crawler/workers', [CrawlerAdminController::class, 'workers'])->middleware('can:crawler.workers.manage')->name('crawler.workers.index');
        Route::post('/crawler/workers/{worker}/disable', [CrawlerAdminController::class, 'disable'])->middleware('can:crawler.workers.manage')->name('crawler.workers.disable');
        Route::post('/crawler/workers/{worker}/credentials/rotate', [CrawlerAdminController::class, 'rotate'])->middleware('can:crawler.workers.manage')->name('crawler.workers.credentials.rotate');
        Route::delete('/crawler/workers/{worker}/credentials/{credential}', [CrawlerAdminController::class, 'revoke'])->middleware('can:crawler.workers.manage')->name('crawler.workers.credentials.revoke');
        Route::patch('/crawler/sources/{source}/configuration', [CrawlerAdminController::class, 'updateSource'])->middleware('can:crawler.sources.manage')->name('crawler.sources.update');
        Route::get('/crawler/jobs', [CrawlerAdminController::class, 'jobs'])->middleware('can:crawler.jobs.view')->name('crawler.jobs.index');
        Route::post('/crawler/sources/{source}/jobs', [CrawlerAdminController::class, 'createJob'])->middleware('can:crawler.jobs.manage')->name('crawler.jobs.store');
        Route::post('/crawler/jobs/{job}/cancel', [CrawlerAdminController::class, 'cancel'])->middleware('can:crawler.jobs.manage')->name('crawler.jobs.cancel');
        Route::post('/crawler/jobs/{job}/retry', [CrawlerAdminController::class, 'retry'])->middleware('can:crawler.jobs.manage')->name('crawler.jobs.retry');
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
        Route::post('/data-quality/observations', [DataQualityController::class, 'storeObservation'])->middleware('can:scholarships.data-quality.manage')->name('data-quality.observations.store');
        Route::get('/data-quality/observations', [DataQualityController::class, 'observations'])->middleware('can:scholarships.review.view')->name('data-quality.observations.index');
        Route::get('/data-quality/observations/{observation}/artifact', [DataQualityController::class, 'artifact'])->middleware('can:scholarships.review.view')->name('data-quality.observations.artifact');
        Route::post('/data-quality/observations/{observation}/processing-runs', [DataQualityController::class, 'processObservation'])->middleware('can:scholarships.data-quality.manage')->name('data-quality.processing-runs.store');
        Route::get('/review-tasks', [DataQualityController::class, 'reviewTasks'])->middleware('can:scholarships.review.view')->name('review-tasks.index');
        Route::get('/review-tasks/{task}', [DataQualityController::class, 'showReviewTask'])->middleware('can:scholarships.review.view')->name('review-tasks.show');
        Route::post('/review-tasks/{task}/decisions', [DataQualityController::class, 'decide'])->middleware('can:scholarships.review.manage')->name('review-tasks.decisions.store');
        Route::post('/scholarship-versions/{version}/verify', [ScholarshipVersionWorkflowController::class, 'verify'])->middleware('can:scholarships.verify')->name('scholarship-versions.verify');
        Route::post('/scholarship-versions/{version}/reject', [ScholarshipVersionWorkflowController::class, 'reject'])->middleware('can:scholarships.verify')->name('scholarship-versions.reject');
        Route::post('/cycles/{cycle}/publish', [ScholarshipCyclePublicationController::class, 'publish'])->middleware('can:scholarships.publish')->name('cycles.publish');
        Route::post('/cycles/{cycle}/unpublish', [ScholarshipCyclePublicationController::class, 'unpublish'])->middleware('can:scholarships.publish')->name('cycles.unpublish');
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
