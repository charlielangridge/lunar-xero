<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero;

use CharlieLangridge\LunarXero\Commands\BackfillXeroItemCodes;
use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Filament\LunarXeroPlugin;
use CharlieLangridge\LunarXero\Filament\Pages\ManageXeroSettings;
use CharlieLangridge\LunarXero\Filament\Resources\ProductResource\Pages\ManageProductXero;
use CharlieLangridge\LunarXero\Filament\Resources\ProductVariantResource\Pages\ManageVariantXero;
use CharlieLangridge\LunarXero\Listeners\DispatchOrderInvoiceSync;
use CharlieLangridge\LunarXero\Listeners\DispatchPaymentSync;
use CharlieLangridge\LunarXero\Lunar\Extensions\EditCustomerPageExtension;
use CharlieLangridge\LunarXero\Lunar\Extensions\ManageOrderPageExtension;
use CharlieLangridge\LunarXero\Lunar\Extensions\ProductResourceExtension;
use CharlieLangridge\LunarXero\Lunar\Extensions\ProductVariantResourceExtension;
use CharlieLangridge\LunarXero\Observers\LunarCustomerObserver;
use CharlieLangridge\LunarXero\Observers\LunarOrderObserver;
use CharlieLangridge\LunarXero\Observers\LunarProductObserver;
use CharlieLangridge\LunarXero\Observers\LunarProductVariantObserver;
use CharlieLangridge\LunarXero\Observers\LunarTransactionObserver;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroClient;
use CharlieLangridge\LunarXero\Services\XeroSyncService;
use CharlieLangridge\LunarXero\Support\LunarModelResolver;
use CharlieLangridge\LunarXero\Support\XeroAccountOptions;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\EditCustomer;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Filament\Resources\ProductVariantResource;
use Lunar\Admin\Support\Facades\LunarPanel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LunarXeroServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('lunarpanel-xero')
            ->hasConfigFile('lunarpanel-xero')
            ->hasViews()
            ->hasRoute('web')
            ->hasCommand(BackfillXeroItemCodes::class);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(XeroSettingsRepository::class);
        $this->app->singleton(XeroClientInterface::class, XeroClient::class);
        $this->app->singleton(XeroClient::class);
        $this->app->singleton(XeroSyncService::class);
        $this->app->singleton(XeroAccountOptions::class);
        $this->app->singleton(LunarModelResolver::class);
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerEventListeners();
        $this->registerModelObservers();
        $this->registerFilamentIntegration();
    }

    protected function registerEventListeners(): void
    {
        Event::listen(
            config('lunarpanel-xero.events.order_created'),
            DispatchOrderInvoiceSync::class,
        );

        Event::listen(
            config('lunarpanel-xero.events.payment_completed'),
            DispatchPaymentSync::class,
        );
    }

    protected function registerFilamentIntegration(): void
    {
        if (class_exists(Livewire::class) && $this->app->bound('livewire')) {
            Livewire::component(
                'charlie-langridge.lunar-xero.filament.pages.manage-xero-settings',
                ManageXeroSettings::class,
            );
            Livewire::component(
                'charlie-langridge.lunar-xero.filament.resources.product-variant-resource.pages.manage-variant-xero',
                ManageVariantXero::class,
            );
            Livewire::component(
                'charlie-langridge.lunar-xero.filament.resources.product-resource.pages.manage-product-xero',
                ManageProductXero::class,
            );
        }

        if (class_exists(LunarPanel::class) && $this->app->bound('lunar-panel')) {
            LunarPanel::extensions($this->getLunarPanelExtensions());
        }

        if (! $this->app->bound('filament')) {
            return;
        }

        $panel = Filament::getPanel('lunar', false);

        if ($panel && ! $panel->hasPlugin('lunarpanel-xero')) {
            $panel->plugin(app(LunarXeroPlugin::class));
        }
    }

    protected function getLunarPanelExtensions(): array
    {
        $extensions = [
            ProductResource::class => ProductResourceExtension::class,
            ProductVariantResource::class => ProductVariantResourceExtension::class,
            EditCustomer::class => EditCustomerPageExtension::class,
            ManageOrder::class => ManageOrderPageExtension::class,
        ];

        $appManageOrderPage = 'App\\Lunar\\Admin\\Filament\\Resources\\OrderResource\\Pages\\ManageOrder';

        if (class_exists($appManageOrderPage)) {
            $extensions[$appManageOrderPage] = ManageOrderPageExtension::class;
        }

        return $extensions;
    }

    protected function registerModelObservers(): void
    {
        $customerModel = app(LunarModelResolver::class)->customerModel();
        $orderModel = app(LunarModelResolver::class)->orderModel();
        $productModel = app(LunarModelResolver::class)->productModel();
        $variantModel = app(LunarModelResolver::class)->variantModel();
        $transactionModel = app(LunarModelResolver::class)->transactionModel();

        $customerModel::observe(app(LunarCustomerObserver::class));
        $orderModel::observe(app(LunarOrderObserver::class));
        $productModel::observe(app(LunarProductObserver::class));
        $variantModel::observe(app(LunarProductVariantObserver::class));
        $transactionModel::observe(app(LunarTransactionObserver::class));
    }
}
