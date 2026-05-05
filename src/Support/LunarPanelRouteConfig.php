<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Container\Container;

class LunarPanelRouteConfig
{
    public static function oauthMiddleware(): array
    {
        $configuredMiddleware = config('lunarpanel-xero.routes.middleware');

        if (is_array($configuredMiddleware) && $configuredMiddleware !== []) {
            return $configuredMiddleware;
        }

        $panel = static::hasFilamentBinding()
            ? Filament::getPanel('lunar', isStrict: false)
            : null;

        if (! $panel) {
            return ['web', 'panel:lunar', FilamentAuthenticate::class];
        }

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

        $panel = static::hasFilamentBinding()
            ? Filament::getPanel('lunar', isStrict: false)
            : null;

        if (! $panel) {
            return 'lunar/xero';
        }

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
