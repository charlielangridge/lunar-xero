<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Filament\Pages\ManageXeroSettings;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;

it('shows tenant selection when xero is authorised but no tenant is active', function (): void {
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

    $page = new ManageXeroSettings;
    $page->mount();

    $viewData = $page->getViewData();

    expect($viewData['isAuthorized'])->toBeTrue()
        ->and($viewData['isConnected'])->toBeFalse()
        ->and($viewData['tenants'])->toHaveCount(2)
        ->and($page->activeTenantId)->toBeNull();
});
