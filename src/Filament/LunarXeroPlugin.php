<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Filament;

use CharlieLangridge\LunarXero\Filament\Pages\ManageXeroSettings;
use Filament\Contracts\Plugin;
use Filament\Panel;

class LunarXeroPlugin implements Plugin
{
    public function getId(): string
    {
        return 'lunarpanel-xero';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            ManageXeroSettings::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
