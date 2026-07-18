<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Filament\Pages;

use BackedEnum;
use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Enums\InvoiceStatus;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Support\XeroAccountOptions;
use CharlieLangridge\LunarXero\Support\XeroNavigationIcon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use UnitEnum;

class ManageXeroSettings extends Page
{
    protected static ?string $navigationLabel = 'Xero';

    protected static ?string $title = 'Xero Settings';

    protected static ?string $slug = 'xero-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'lunarpanel-xero::filament.pages.manage-xero-settings';

    public ?string $invoiceStatus = null;

    public ?string $activeTenantId = null;

    public ?string $defaultAccountCode = null;

    /**
     * @var array<int, array{payment_type:string,account_code:?string,account_name:?string}>
     */
    public array $paymentMappings = [];

    public function mount(): void
    {
        $settingsRepository = app(XeroSettingsRepository::class);
        $settings = $settingsRepository->getSettings();
        $activeTenant = $settingsRepository->getActiveTenant();
        $existingMappings = $settingsRepository->getPaymentMappings()->keyBy('payment_type');

        $this->invoiceStatus = $settingsRepository->getInvoiceStatus();
        $this->activeTenantId = $activeTenant?->tenant_id ?? $settings->active_tenant_id;
        $this->defaultAccountCode = $settings->default_account_code;
        $this->paymentMappings = collect($this->getLunarPaymentTypes())
            ->map(fn (string $label, string $paymentType): array => [
                'payment_type' => $paymentType,
                'payment_label' => $label,
                'account_code' => $existingMappings->get($paymentType)?->account_code,
                'account_name' => $existingMappings->get($paymentType)?->account_name,
            ])
            ->values()
            ->all();
    }

    public function save(): void
    {
        $settingsRepository = app(XeroSettingsRepository::class);
        $accountOptions = app(XeroAccountOptions::class);

        $settingsRepository->setInvoiceStatus($this->invoiceStatus ?: InvoiceStatus::Draft->value);
        $settingsRepository->setDefaultAccountCode($this->defaultAccountCode);

        if ($this->activeTenantId) {
            $settingsRepository->setActiveTenant($this->activeTenantId);
        }

        $settingsRepository->syncPaymentMappings(
            collect($this->paymentMappings)
                ->filter(fn (array $mapping): bool => filled($mapping['payment_type'] ?? null) && filled($mapping['account_code'] ?? null))
                ->map(function (array $mapping) use ($accountOptions): array {
                    $mapping['account_name'] = $accountOptions->label($mapping['account_code'], true);

                    return $mapping;
                })
                ->values()
                ->all(),
        );

        Notification::make()
            ->title('Xero settings saved')
            ->success()
            ->send();

        $this->mount();
    }

    public function disconnect(): void
    {
        app(XeroClientInterface::class)->disconnect();

        Notification::make()
            ->title('Xero disconnected')
            ->success()
            ->send();

        $this->mount();
    }

    public function refreshTenants(): void
    {
        $tenants = app(XeroClientInterface::class)->fetchTenants();

        if ($tenants->count() === 1) {
            app(XeroSettingsRepository::class)->setActiveTenant($tenants->sole()->tenantId);
        }

        Notification::make()
            ->title('Xero tenants refreshed')
            ->success()
            ->send();

        $this->mount();
    }

    public function getViewData(): array
    {
        $settingsRepository = app(XeroSettingsRepository::class);
        $token = $settingsRepository->getToken();
        $tenant = $settingsRepository->getActiveTenant();
        $isAuthorized = filled($token?->access_token);
        $isConnected = $isAuthorized && filled($tenant?->tenant_id);

        $accountOptions = [];
        $paymentAccountOptions = [];

        if ($isConnected) {
            $options = app(XeroAccountOptions::class);
            $accountOptions = $options->invoiceOptions();
            $paymentAccountOptions = $options->options(true);
        }

        return [
            'settings' => $settingsRepository->getSettings(),
            'tenant' => $tenant,
            'token' => $token,
            'tenants' => $settingsRepository->getTenants(),
            'accountOptions' => $accountOptions,
            'paymentAccountOptions' => $paymentAccountOptions,
            'isAuthorized' => $isAuthorized,
            'isConnected' => $isConnected,
            'hasPaymentTypes' => $this->paymentMappings !== [],
            'invoiceStatuses' => [
                InvoiceStatus::Draft->value => 'Draft',
                InvoiceStatus::Authorised->value => 'Authorised',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getLunarPaymentTypes(): array
    {
        $paymentTypes = config('lunar.payments.types', []);

        if (! is_array($paymentTypes)) {
            return [];
        }

        return collect($paymentTypes)
            ->mapWithKeys(function (mixed $definition, string $paymentType): array {
                $driver = is_array($definition) ? ($definition['driver'] ?? null) : null;
                $label = str($paymentType)->replace('-', ' ')->replace('_', ' ')->title()->toString();

                if (filled($driver)) {
                    $label .= ' ('.str((string) $driver)->replace('-', ' ')->replace('_', ' ')->title()->toString().')';
                }

                return [$paymentType => $label];
            })
            ->sortKeys()
            ->all();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return 'Settings';
    }

    public static function getNavigationIcon(): string|BackedEnum|HtmlString|null
    {
        return XeroNavigationIcon::make();
    }
}
