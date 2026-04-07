<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Exceptions\LunarXeroException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class XeroAccountOptions
{
    public function __construct(
        protected XeroClientInterface $client,
    ) {}

    /**
     * @return array<string, string>
     */
    public function options(bool $paymentsOnly = false): array
    {
        $key = config('lunarpanel-xero.cache.accounts_key').($paymentsOnly ? '.payments' : '.all');
        $ttl = (int) config('lunarpanel-xero.defaults.account_cache_ttl', 600);

        try {
            return Cache::remember($key, $ttl, function () use ($paymentsOnly): array {
                return $this->client
                    ->getAccounts($paymentsOnly)
                    ->mapWithKeys(fn ($account) => [$account->code => $account->label()])
                    ->all();
            });
        } catch (LunarXeroException) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    public function invoiceOptions(): array
    {
        $key = config('lunarpanel-xero.cache.accounts_key').'.invoice';
        $ttl = (int) config('lunarpanel-xero.defaults.account_cache_ttl', 600);

        try {
            return Cache::remember($key, $ttl, function (): array {
                return $this->client
                    ->getAccounts()
                    ->filter(fn ($account): bool => $this->isInvoiceAccount($account->type ?? null, $account->class ?? null))
                    ->mapWithKeys(fn ($account) => [$account->code => $account->label()])
                    ->all();
            });
        } catch (LunarXeroException) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    public function search(string $search, bool $paymentsOnly = false): array
    {
        return Collection::make($this->options($paymentsOnly))
            ->filter(fn (string $label, string $code): bool => str_contains(mb_strtolower($label), mb_strtolower($search)) || str_contains(mb_strtolower($code), mb_strtolower($search)))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function searchInvoiceOptions(string $search): array
    {
        return Collection::make($this->invoiceOptions())
            ->filter(fn (string $label, string $code): bool => str_contains(mb_strtolower($label), mb_strtolower($search)) || str_contains(mb_strtolower($code), mb_strtolower($search)))
            ->all();
    }

    public function label(?string $value, bool $paymentsOnly = false): ?string
    {
        if (! $value) {
            return null;
        }

        return $this->options($paymentsOnly)[$value] ?? $value;
    }

    public function invoiceLabel(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $this->invoiceOptions()[$value] ?? $value;
    }

    public function flush(): void
    {
        Cache::forget(config('lunarpanel-xero.cache.accounts_key').'.all');
        Cache::forget(config('lunarpanel-xero.cache.accounts_key').'.payments');
        Cache::forget(config('lunarpanel-xero.cache.accounts_key').'.invoice');
    }

    protected function isInvoiceAccount(?string $type, ?string $class): bool
    {
        if (strtoupper((string) $class) === 'REVENUE') {
            return true;
        }

        return in_array(strtoupper((string) $type), ['SALES', 'OTHERINCOME', 'REVENUE'], true);
    }
}
