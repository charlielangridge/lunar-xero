<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Lunar\Extensions\ManageOrderPageExtension;
use Filament\Schemas\Components\Section;

it('prepends the xero panel to the order sidebar schema', function (): void {
    $existingSection = Section::make('Existing');

    $schema = app(ManageOrderPageExtension::class)->extendInfolistAsideSchema([
        $existingSection,
    ]);

    expect($schema)->toHaveCount(2)
        ->and($schema[0])->toBeInstanceOf(Section::class)
        ->and($schema[0]->getHeading())->toBe('Xero')
        ->and($schema[1])->toBe($existingSection);
});
