<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Repositories;

use CharlieLangridge\LunarXero\Enums\InvoiceStatus;
use CharlieLangridge\LunarXero\Models\XeroOAuthToken;
use CharlieLangridge\LunarXero\Models\XeroPaymentTypeMapping;
use CharlieLangridge\LunarXero\Models\XeroSettings;
use CharlieLangridge\LunarXero\Models\XeroTenant;
use Illuminate\Support\Collection;

class XeroSettingsRepository
{
    public function getSettings(): XeroSettings
    {
        return XeroSettings::query()->firstOrCreate(
            ['singleton_key' => 'default'],
            [
                'invoice_status' => config('lunarpanel-xero.defaults.invoice_status', InvoiceStatus::Draft->value),
                'connection_meta' => [],
            ],
        );
    }

    public function getToken(): ?XeroOAuthToken
    {
        return XeroOAuthToken::query()->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeToken(array $attributes): XeroOAuthToken
    {
        return tap(XeroOAuthToken::query()->firstOrNew(['singleton_key' => 'default']), function (XeroOAuthToken $token) use ($attributes): void {
            $token->fill($attributes);
            $token->singleton_key = 'default';
            $token->save();
        });
    }

    public function clearToken(): void
    {
        XeroOAuthToken::query()->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $tenants
     */
    public function replaceTenants(array $tenants): void
    {
        $activeTenant = $this->getActiveTenant();
        $activeTenantId = $activeTenant->tenant_id ?? $this->getSettings()->active_tenant_id;
        $activeTenantWasFound = false;

        XeroTenant::query()->delete();

        foreach ($tenants as $tenant) {
            $isActive = filled($activeTenantId) && ($tenant['tenant_id'] ?? null) === $activeTenantId;
            $activeTenantWasFound = $activeTenantWasFound || $isActive;

            XeroTenant::query()->create([
                ...$tenant,
                'is_active' => $isActive,
            ]);
        }

        if ($activeTenantId && ! $activeTenantWasFound) {
            $settings = $this->getSettings();
            $settings->active_tenant_id = null;
            $settings->save();

            $this->updateConnectionMeta([
                'active_tenant_id' => null,
                'active_tenant_name' => null,
            ]);
        }
    }

    public function getActiveTenant(): ?XeroTenant
    {
        return XeroTenant::query()->active()->first();
    }

    public function setActiveTenant(string $tenantId): ?XeroTenant
    {
        XeroTenant::query()->update(['is_active' => false]);

        $tenant = XeroTenant::query()->where('tenant_id', $tenantId)->first();

        if (! $tenant) {
            return null;
        }

        $tenant->forceFill(['is_active' => true])->save();

        $settings = $this->getSettings();
        $settings->active_tenant_id = $tenant->tenant_id;
        $settings->save();

        $this->updateConnectionMeta([
            'active_tenant_name' => $tenant->tenant_name,
            'active_tenant_id' => $tenant->tenant_id,
        ]);

        return $tenant;
    }

    /**
     * @return Collection<int, XeroTenant>
     */
    public function getTenants(): Collection
    {
        return XeroTenant::query()->orderBy('tenant_name')->get();
    }

    public function updateConnectionMeta(array $meta): XeroSettings
    {
        $settings = $this->getSettings();
        $settings->connection_meta = array_replace($settings->connection_meta ?? [], $meta);
        $settings->save();

        return $settings;
    }

    public function disconnect(): void
    {
        $settings = $this->getSettings();
        $settings->forceFill([
            'active_tenant_id' => null,
            'connection_meta' => [],
        ])->save();

        XeroTenant::query()->delete();
        XeroPaymentTypeMapping::query()->delete();
        $this->clearToken();
    }

    public function getInvoiceStatus(): string
    {
        $status = $this->getSettings()->invoice_status;

        return $status instanceof InvoiceStatus ? $status->value : (string) $status;
    }

    public function setInvoiceStatus(string $status): XeroSettings
    {
        $settings = $this->getSettings();
        $settings->invoice_status = $status;
        $settings->save();

        return $settings;
    }

    public function getDefaultAccountCode(): ?string
    {
        return $this->getSettings()->default_account_code;
    }

    public function setDefaultAccountCode(?string $accountCode): XeroSettings
    {
        $settings = $this->getSettings();
        $settings->default_account_code = $accountCode;
        $settings->save();

        return $settings;
    }

    /**
     * @return Collection<int, XeroPaymentTypeMapping>
     */
    public function getPaymentMappings(): Collection
    {
        return XeroPaymentTypeMapping::query()->orderBy('payment_type')->get();
    }

    /**
     * @param  array<int, array{payment_type:string,account_code:string,account_name:?string}>  $mappings
     */
    public function syncPaymentMappings(array $mappings): void
    {
        XeroPaymentTypeMapping::query()->delete();

        foreach ($mappings as $mapping) {
            if (($mapping['payment_type'] ?? null) && ($mapping['account_code'] ?? null)) {
                XeroPaymentTypeMapping::query()->create([
                    'payment_type' => $mapping['payment_type'],
                    'account_code' => $mapping['account_code'],
                    'account_name' => $mapping['account_name'] ?? null,
                ]);
            }
        }
    }

    public function findPaymentMapping(string $paymentType): ?XeroPaymentTypeMapping
    {
        return XeroPaymentTypeMapping::query()->where('payment_type', $paymentType)->first();
    }
}
