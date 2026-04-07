<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Filament\Resources\ProductResource\Pages\ManageProductXero;
use Livewire\Mechanisms\ComponentRegistry;

it('registers the product xero page under its generated livewire alias', function (): void {
    $alias = 'charlie-langridge.lunar-xero.filament.resources.product-resource.pages.manage-product-xero';

    expect(app(ComponentRegistry::class)->getClass($alias))
        ->toBe(ManageProductXero::class);
});
