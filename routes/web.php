<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Http\Controllers\OAuthController;
use CharlieLangridge\LunarXero\Support\LunarPanelRouteConfig;
use Illuminate\Support\Facades\Route;

Route::middleware(LunarPanelRouteConfig::oauthMiddleware())
    ->prefix(LunarPanelRouteConfig::oauthPrefix())
    ->name('lunarpanel-xero.')
    ->group(function (): void {
        Route::get('/connect', [OAuthController::class, 'connect'])->name('connect');
        Route::get('/callback', [OAuthController::class, 'callback'])->name('callback');
        Route::post('/disconnect', [OAuthController::class, 'disconnect'])->name('disconnect');
        Route::post('/tenants/refresh', [OAuthController::class, 'refreshTenants'])->name('tenants.refresh');
        Route::post('/tenants/select', [OAuthController::class, 'selectTenant'])->name('tenants.select');
    });
