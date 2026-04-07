<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Lunar\Extensions;

use CharlieLangridge\LunarXero\Filament\Resources\ProductResource\Pages\ManageProductXero;
use Lunar\Admin\Filament\Resources\ProductResource\Pages\ManageProductIdentifiers;
use Lunar\Admin\Support\Extending\ResourceExtension;

class ProductResourceExtension extends ResourceExtension
{
    public function extendPages(array $pages): array
    {
        $pages['xero'] = ManageProductXero::route('/{record}/xero');

        return $pages;
    }

    public function extendSubNavigation(array $pages): array
    {
        $extendedPages = [];

        foreach ($pages as $page) {
            $extendedPages[] = $page;

            if ($page === ManageProductIdentifiers::class) {
                $extendedPages[] = ManageProductXero::class;
            }
        }

        if (! in_array(ManageProductXero::class, $extendedPages, true)) {
            $extendedPages[] = ManageProductXero::class;
        }

        return $extendedPages;
    }
}
