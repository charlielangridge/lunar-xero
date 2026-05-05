<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Support\LunarPanelRouteConfig;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;

it('registers the xero connect route', function (): void {
    $this->withoutMiddleware();

    $response = $this->get(route('lunarpanel-xero.connect'));

    expect($response->status())->toBe(302);
});

it('uses filament authentication when route middleware is not explicitly configured', function (): void {
    config()->set('lunarpanel-xero.routes.middleware', null);

    expect(LunarPanelRouteConfig::oauthMiddleware())
        ->toContain('panel:lunar')
        ->toContain(FilamentAuthenticate::class)
        ->not->toContain('auth');
});
