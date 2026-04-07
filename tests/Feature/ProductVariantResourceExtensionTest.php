<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Filament\Resources\ProductVariantResource\Pages\ManageVariantXero;
use CharlieLangridge\LunarXero\Lunar\Extensions\ProductVariantResourceExtension;
use Filament\Resources\Pages\PageRegistration;
use Lunar\Admin\Filament\Resources\ProductVariantResource\Pages\EditProductVariant;
use Lunar\Admin\Filament\Resources\ProductVariantResource\Pages\ManageVariantIdentifiers;
use Lunar\Admin\Filament\Resources\ProductVariantResource\Pages\ManageVariantShipping;

it('adds a dedicated xero page to the variant resource pages', function (): void {
    $pages = app(ProductVariantResourceExtension::class)->extendPages([
        'edit' => EditProductVariant::route('/{record}/edit'),
    ]);

    expect($pages)->toHaveKey('xero')
        ->and($pages['xero'])->toBeInstanceOf(PageRegistration::class)
        ->and($pages['xero']->getPage())->toBe(ManageVariantXero::class);
});

it('adds the xero page into variant sub navigation before shipping', function (): void {
    $pages = app(ProductVariantResourceExtension::class)->extendSubNavigation([
        EditProductVariant::class,
        ManageVariantIdentifiers::class,
        ManageVariantShipping::class,
    ]);

    expect($pages)->toBe([
        EditProductVariant::class,
        ManageVariantIdentifiers::class,
        ManageVariantXero::class,
        ManageVariantShipping::class,
    ]);
});
