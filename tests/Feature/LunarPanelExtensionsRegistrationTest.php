<?php

declare(strict_types=1);

namespace App\Lunar\Admin\Filament\Resources\OrderResource\Pages {
    class ManageOrder extends \Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder {}
}

namespace {

    use App\Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
    use CharlieLangridge\LunarXero\Lunar\Extensions\ManageOrderPageExtension;
    use CharlieLangridge\LunarXero\LunarXeroServiceProvider;
    use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder as LunarManageOrder;

    it('registers the order xero extension for app order page overrides when present', function (): void {
        $provider = new LunarXeroServiceProvider(app());

        $method = new ReflectionMethod($provider, 'getLunarPanelExtensions');
        $method->setAccessible(true);

        $extensions = $method->invoke($provider);

        expect($extensions)->toHaveKey(LunarManageOrder::class)
            ->and($extensions[LunarManageOrder::class])->toBe(ManageOrderPageExtension::class)
            ->and($extensions)->toHaveKey(ManageOrder::class)
            ->and($extensions[ManageOrder::class])->toBe(ManageOrderPageExtension::class);
    });

}
