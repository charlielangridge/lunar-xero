<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Data\TenantData;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutMiddleware();
});

it('redirects the connect route to the xero authorization url', function (): void {
    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getAuthorizationUrl')->once()->andReturn('https://login.xero.test/authorize');
    app()->instance(XeroClientInterface::class, $mock);

    $response = $this->get(route('lunarpanel-xero.connect'));

    $response->assertRedirect('https://login.xero.test/authorize');
});

it('handles the oauth callback and redirects back to the fallback xero area', function (): void {
    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('handleCallback')
        ->once()
        ->with('oauth-code', 'oauth-state');
    app()->instance(XeroClientInterface::class, $mock);

    $response = $this->get(route('lunarpanel-xero.callback', [
        'code' => 'oauth-code',
        'state' => 'oauth-state',
    ]));

    $response->assertRedirect(url('lunar/xero'));
    $response->assertSessionHas('status', 'Xero connected successfully.');
});

it('disconnects xero from the route and flashes a success message', function (): void {
    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('disconnect')->once();
    app()->instance(XeroClientInterface::class, $mock);

    $response = $this->from('/previous')->post(route('lunarpanel-xero.disconnect'));

    $response->assertRedirect('/previous');
    $response->assertSessionHas('status', 'Xero disconnected successfully.');
});

it('refreshes tenants and auto-selects the only available tenant', function (): void {
    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('fetchTenants')->once()->andReturn(collect([
        new TenantData(
            tenantId: 'tenant-1',
            tenantName: 'Primary Org',
        ),
    ]));
    app()->instance(XeroClientInterface::class, $mock);

    app(XeroSettingsRepository::class)->replaceTenants([
        [
            'tenant_id' => 'tenant-1',
            'tenant_name' => 'Primary Org',
            'tenant_type' => 'ORGANISATION',
            'payload' => [],
            'is_active' => false,
        ],
    ]);

    $response = $this->from('/previous')->post(route('lunarpanel-xero.tenants.refresh'));

    $response->assertRedirect('/previous');
    $response->assertSessionHas('status', 'Xero tenants refreshed.');
    expect(app(XeroSettingsRepository::class)->getActiveTenant()?->tenant_id)->toBe('tenant-1');
});

it('selects the requested tenant from the route', function (): void {
    app(XeroSettingsRepository::class)->replaceTenants([
        [
            'tenant_id' => 'tenant-a',
            'tenant_name' => 'Org A',
            'tenant_type' => 'ORGANISATION',
            'payload' => [],
            'is_active' => false,
        ],
        [
            'tenant_id' => 'tenant-b',
            'tenant_name' => 'Org B',
            'tenant_type' => 'ORGANISATION',
            'payload' => [],
            'is_active' => false,
        ],
    ]);

    $response = $this->from('/previous')->post(route('lunarpanel-xero.tenants.select'), [
        'tenant_id' => 'tenant-b',
    ]);

    $response->assertRedirect('/previous');
    $response->assertSessionHas('status', 'Active Xero tenant updated.');
    expect(app(XeroSettingsRepository::class)->getActiveTenant()?->tenant_id)->toBe('tenant-b');
});

it('prefers the filament xero page route after oauth callback when available', function (): void {
    Route::get('/filament-xero-settings', fn () => 'ok')->name('filament.admin.pages.xero-settings');

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('handleCallback')
        ->once()
        ->with('oauth-code', 'oauth-state');
    app()->instance(XeroClientInterface::class, $mock);

    $response = $this->get(route('lunarpanel-xero.callback', [
        'code' => 'oauth-code',
        'state' => 'oauth-state',
    ]));

    $response->assertRedirect(route('filament.admin.pages.xero-settings'));
});
