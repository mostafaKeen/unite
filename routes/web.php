<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BitrixWidgetController;
use App\Http\Controllers\BitrixOAuthController;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantAccess;

// 1. Authenticated Main Portal (Outside Bitrix24)
Route::middleware(['auth'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ONLY Super Admin can create, update, or manage tenant companies
    Route::prefix('tenants')->middleware([EnsureSuperAdmin::class])->group(function () {
        Route::post('/', [TenantController::class, 'store'])->name('tenants.store');
        Route::put('/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::post('/{tenant}/bind-placements', [TenantController::class, 'registerBitrixPlacements'])->name('tenants.bind-placements');
    });

    // Tenant testing & sync (Super Admin or Tenant Admin for their assigned tenant)
    Route::prefix('tenants/{tenant}')->middleware([EnsureTenantAccess::class, 'throttle:15,1'])->group(function () {
        Route::post('/test-unite', [TenantController::class, 'testUniteConnection'])->name('tenants.test-unite');
        Route::post('/sync-directories', [TenantController::class, 'syncDirectories'])->name('tenants.sync-directories');
    });

    // User Management (Super Admin manages all; Tenant Admin manages their tenant's users)
    Route::prefix('api/users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('users.index');
        Route::post('/', [UserController::class, 'store'])->name('users.store');
        Route::put('/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});

// 2. Bitrix24 OAuth Flows (Public / Webhook endpoints authorized by Bitrix24)
Route::get('/b24/oauth/redirect/{tenant}', [BitrixOAuthController::class, 'redirect'])->name('b24.oauth.redirect');
Route::get('/b24/oauth/callback', [BitrixOAuthController::class, 'callback'])->name('b24.oauth.callback');
Route::post('/api/b24/webhook/{tenant}', [BitrixOAuthController::class, 'handleWebhook'])
    ->middleware(['throttle:60,1'])
    ->name('b24.webhook');

// 3. Bitrix24 Embedded CRM Deal Tab Widget (Authorized via Bitrix24 context / iframe with rate limiting)
Route::prefix('b24/widget/deal-tab/{tenant}')->middleware(['throttle:60,1'])->group(function () {
    Route::get('/', [BitrixWidgetController::class, 'show'])->name('b24.widget.show');
    Route::get('/slots', [BitrixWidgetController::class, 'getAvailableSlots'])->name('b24.widget.slots');
    Route::post('/book', [BitrixWidgetController::class, 'bookAppointment'])->name('b24.widget.book');
    Route::post('/status/{appointment}', [BitrixWidgetController::class, 'updateStatus'])->name('b24.widget.status');
    Route::post('/invoice/{appointment}', [BitrixWidgetController::class, 'createInvoice'])->name('b24.widget.invoice');
});

require __DIR__.'/settings.php';
