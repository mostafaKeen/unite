<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BitrixWidgetController;
use App\Http\Controllers\BitrixOAuthController;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantAccess;

// 1. Root & Dashboard Portal
Route::match(['get', 'post'], '/', function (\Illuminate\Http\Request $request) {
    if ($request->has('AUTH_ID') || $request->has('DOMAIN') || $request->has('member_id')) {
        return app(\App\Http\Controllers\BitrixOAuthController::class)->autoLogin($request);
    }
    if (\Illuminate\Support\Facades\Auth::check()) {
        return app(\App\Http\Controllers\DashboardController::class)->index($request);
    }
    return redirect()->route('login');
})->name('home');

Route::match(['get', 'post'], '/dashboard', function (\Illuminate\Http\Request $request) {
    if ($request->has('AUTH_ID') || $request->has('DOMAIN') || $request->has('member_id')) {
        return app(\App\Http\Controllers\BitrixOAuthController::class)->autoLogin($request);
    }
    if (!\Illuminate\Support\Facades\Auth::check()) {
        return redirect()->route('login');
    }
    return app(\App\Http\Controllers\DashboardController::class)->index($request);
})->name('dashboard');

Route::middleware(['auth'])->group(function () {

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

// 2. Bitrix24 Zero-Button Auto-Login, Local App & OAuth Flows
Route::match(['get', 'post'], '/b24/install', [BitrixOAuthController::class, 'autoLogin'])->name('b24.install');
Route::match(['get', 'post'], '/b24/auto-login', [BitrixOAuthController::class, 'autoLogin'])->name('b24.auto-login');
Route::match(['get', 'post'], '/b24/app/{tenant?}', [BitrixOAuthController::class, 'autoLogin'])->name('b24.app.launch');
Route::get('/b24/token-login', [BitrixOAuthController::class, 'tokenLogin'])->name('b24.token-login');
Route::get('/b24/oauth/redirect/{tenant}', [BitrixOAuthController::class, 'redirect'])->name('b24.oauth.redirect');
Route::get('/b24/oauth/callback', [BitrixOAuthController::class, 'callback'])->name('b24.oauth.callback');
Route::get('/b24/auth/user-redirect/{tenant}', [BitrixOAuthController::class, 'userRedirect'])->name('b24.auth.user-redirect');
Route::get('/b24/auth/user-callback', [BitrixOAuthController::class, 'userCallback'])->name('b24.auth.user-callback');
Route::post('/api/b24/webhook/{tenant}', [BitrixOAuthController::class, 'handleWebhook'])
    ->middleware(['throttle:60,1'])
    ->name('b24.webhook');

// 3. Bitrix24 Embedded CRM Detail Tab Widget (Authorized via Bitrix24 context / iframe with rate limiting)
Route::prefix('b24/widget/deal-tab/{tenant}')->middleware(['throttle:60,1'])->group(function () {
    Route::match(['get', 'post'], '/', [BitrixWidgetController::class, 'show'])->name('b24.widget.show');
    Route::get('/slots', [BitrixWidgetController::class, 'getAvailableSlots'])->name('b24.widget.slots');
    Route::post('/book', [BitrixWidgetController::class, 'bookAppointment'])->name('b24.widget.book');
    Route::post('/status/{appointment}', [BitrixWidgetController::class, 'updateStatus'])->name('b24.widget.status');
    Route::post('/invoice/{appointment}', [BitrixWidgetController::class, 'createInvoice'])->name('b24.widget.invoice');
});

require __DIR__.'/settings.php';
