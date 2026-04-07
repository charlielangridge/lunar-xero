<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Filament\Resources\ProductResource\Pages\ManageProductXero;
use CharlieLangridge\LunarXero\Lunar\Extensions\ProductResourceExtension;
use Filament\Resources\Pages\PageRegistration;
use Lunar\Admin\Filament\Resources\ProductResource\Pages\EditProduct;
use Lunar\Admin\Filament\Resources\ProductResource\Pages\ManageProductIdentifiers;
use Lunar\Admin\Filament\Resources\ProductResource\Pages\ManageProductInventory;

it('adds a dedicated xero page to the product resource pages', function (): void {
    $pages = app(ProductResourceExtension::class)->extendPages([
        'edit' => EditProduct::route('/{record}/edit'),
    ]);

    expect($pages)->toHaveKey('xero')
        ->and($pages['xero'])->toBeInstanceOf(PageRegistration::class)
        ->and($pages['xero']->getPage())->toBe(ManageProductXero::class);
});

it('adds the xero page into product sub navigation after identifiers', function (): void {
    $pages = app(ProductResourceExtension::class)->extendSubNavigation([
        EditProduct::class,
        ManageProductIdentifiers::class,
        ManageProductInventory::class,
    ]);

    expect($pages)->toBe([
        EditProduct::class,
        ManageProductIdentifiers::class,
        ManageProductXero::class,
        ManageProductInventory::class,
    ]);
});
