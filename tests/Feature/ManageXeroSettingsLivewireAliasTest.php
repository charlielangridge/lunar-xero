<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Filament\Pages\ManageXeroSettings;
use Livewire\Mechanisms\ComponentRegistry;

it('registers the xero settings filament page under its generated livewire alias', function (): void {
    $alias = 'charlie-langridge.lunar-xero.filament.pages.manage-xero-settings';

    expect(app(ComponentRegistry::class)->getClass($alias))
        ->toBe(ManageXeroSettings::class);
});
