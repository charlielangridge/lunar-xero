<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Lunar\Extensions;

use CharlieLangridge\LunarXero\Filament\Resources\ProductVariantResource\Pages\ManageVariantXero;
use Lunar\Admin\Support\Extending\ResourceExtension;

class ProductVariantResourceExtension extends ResourceExtension
{
    public function extendPages(array $pages): array
    {
        $pages['xero'] = ManageVariantXero::route('/{record}/xero');

        return $pages;
    }

    public function extendSubNavigation(array $pages): array
    {
        $shipping = array_pop($pages);

        $pages[] = ManageVariantXero::class;

        if ($shipping) {
            $pages[] = $shipping;
        }

        return $pages;
    }
}
