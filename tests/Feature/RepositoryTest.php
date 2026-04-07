<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;

it('creates a singleton settings row', function (): void {
    $repository = app(XeroSettingsRepository::class);

    $first = $repository->getSettings();
    $second = $repository->getSettings();

    expect($first->is($second))->toBeTrue()
        ->and($first->singleton_key)->toBe('default');
});

it('stores and clears the singleton token', function (): void {
    $repository = app(XeroSettingsRepository::class);

    $token = $repository->storeToken([
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_type' => 'Bearer',
    ]);

    expect($token->access_token)->toBe('access-token');

    $repository->clearToken();

    expect($repository->getToken())->toBeNull();
});

it('sets the active tenant and stores connection metadata', function (): void {
    $repository = app(XeroSettingsRepository::class);

    $repository->replaceTenants([
        [
            'tenant_id' => 'tenant-1',
            'tenant_name' => 'Primary Org',
            'tenant_type' => 'ORGANISATION',
            'payload' => ['tenantId' => 'tenant-1'],
            'is_active' => false,
        ],
        [
            'tenant_id' => 'tenant-2',
            'tenant_name' => 'Secondary Org',
            'tenant_type' => 'ORGANISATION',
            'payload' => ['tenantId' => 'tenant-2'],
            'is_active' => false,
        ],
    ]);

    $tenant = $repository->setActiveTenant('tenant-2');

    expect($tenant?->tenant_id)->toBe('tenant-2')
        ->and($repository->getActiveTenant()?->tenant_id)->toBe('tenant-2')
        ->and($repository->getSettings()->active_tenant_id)->toBe('tenant-2')
        ->and($repository->getSettings()->connection_meta)->toMatchArray([
            'active_tenant_id' => 'tenant-2',
            'active_tenant_name' => 'Secondary Org',
        ]);
});

it('disconnects by clearing tokens tenants mappings and active tenant state', function (): void {
    $repository = app(XeroSettingsRepository::class);

    $repository->storeToken([
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_type' => 'Bearer',
    ]);

    $repository->replaceTenants([
        [
            'tenant_id' => 'tenant-1',
            'tenant_name' => 'Primary Org',
            'tenant_type' => 'ORGANISATION',
            'payload' => ['tenantId' => 'tenant-1'],
            'is_active' => true,
        ],
    ]);

    $repository->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);

    $repository->setActiveTenant('tenant-1');
    $repository->disconnect();

    expect($repository->getToken())->toBeNull()
        ->and($repository->getActiveTenant())->toBeNull()
        ->and($repository->getTenants())->toHaveCount(0)
        ->and($repository->getPaymentMappings())->toHaveCount(0)
        ->and($repository->getSettings()->active_tenant_id)->toBeNull()
        ->and($repository->getSettings()->connection_meta)->toBe([]);
});
