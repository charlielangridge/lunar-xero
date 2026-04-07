<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Filament\Resources\ProductVariantResource\Pages\ManageVariantXero;
use Livewire\Mechanisms\ComponentRegistry;

it('registers the variant xero page under its generated livewire alias', function (): void {
    $alias = 'charlie-langridge.lunar-xero.filament.resources.product-variant-resource.pages.manage-variant-xero';

    expect(app(ComponentRegistry::class)->getClass($alias))
        ->toBe(ManageVariantXero::class);
});
