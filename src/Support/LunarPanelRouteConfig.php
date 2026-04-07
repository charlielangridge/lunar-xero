<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

use Filament\Facades\Filament;
use Illuminate\Container\Container;

class LunarPanelRouteConfig
{
    public static function oauthMiddleware(): array
    {
        $configuredMiddleware = config('lunarpanel-xero.routes.middleware');

        if (! is_array($configuredMiddleware)) {
            return ['web', 'auth'];
        }

        if ($configuredMiddleware !== []) {
            return $configuredMiddleware;
        }

        if (! static::hasFilamentBinding()) {
            return ['web', 'auth'];
        }

        $panel = Filament::getPanel('lunar', isStrict: false);

        return array_values(array_unique([
            ...$panel->getMiddleware(),
            ...$panel->getAuthMiddleware(),
        ]));
    }

    public static function oauthPrefix(): string
    {
        $configuredPrefix = config('lunarpanel-xero.routes.prefix');

        if (filled($configuredPrefix)) {
            return trim((string) $configuredPrefix, '/');
        }

        if (! static::hasFilamentBinding()) {
            return 'lunar/xero';
        }

        $panel = Filament::getPanel('lunar', isStrict: false);
        $panelPath = $panel->getPath();

        if (filled($panelPath)) {
            return trim((string) $panelPath, '/').'/xero';
        }

        return 'lunar/xero';
    }

    protected static function hasFilamentBinding(): bool
    {
        return Container::getInstance()->bound('filament');
    }
}
