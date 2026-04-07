<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Http\Controllers;

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Support\LunarPanelRouteConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

class OAuthController extends Controller
{
    public function __construct(
        protected XeroClientInterface $client,
        protected XeroSettingsRepository $settingsRepository,
    ) {}

    public function connect(): RedirectResponse
    {
        return redirect()->away($this->client->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $this->client->handleCallback(
            code: (string) $request->string('code'),
            state: (string) $request->string('state'),
        );

        $pageRouteName = collect([
            'filament.lunar.pages.xero-settings',
            'filament.admin.pages.xero-settings',
        ])->first(fn (string $routeName): bool => Route::has($routeName));

        $redirectTo = $pageRouteName
            ? route($pageRouteName)
            : url(LunarPanelRouteConfig::oauthPrefix());

        return redirect($redirectTo)->with('status', 'Xero connected successfully.');
    }

    public function disconnect(): RedirectResponse
    {
        $this->client->disconnect();

        return back()->with('status', 'Xero disconnected successfully.');
    }

    public function refreshTenants(): RedirectResponse
    {
        $tenants = $this->client->fetchTenants();

        if ($tenants->count() === 1) {
            $this->settingsRepository->setActiveTenant($tenants->sole()->tenantId);
        }

        return back()->with('status', 'Xero tenants refreshed.');
    }

    public function selectTenant(Request $request): RedirectResponse
    {
        $request->validate([
            'tenant_id' => ['required', 'string'],
        ]);

        $this->settingsRepository->setActiveTenant((string) $request->string('tenant_id'));

        return back()->with('status', 'Active Xero tenant updated.');
    }
}
