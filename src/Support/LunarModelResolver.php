<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

use CharlieLangridge\LunarXero\Exceptions\XeroConfigurationException;
use Illuminate\Database\Eloquent\Model;

class LunarModelResolver
{
    public function customerModel(): string
    {
        return $this->resolveConfiguredClass('customer');
    }

    public function productModel(): string
    {
        return $this->resolveConfiguredClass('product');
    }

    public function variantModel(): string
    {
        return $this->resolveConfiguredClass('variant');
    }

    public function orderModel(): string
    {
        return $this->resolveConfiguredClass('order');
    }

    public function transactionModel(): string
    {
        return $this->resolveConfiguredClass('transaction');
    }

    protected function resolveConfiguredClass(string $key): string
    {
        $class = config("lunarpanel-xero.models.{$key}");

        if (! is_string($class) || ! class_exists($class)) {
            throw new XeroConfigurationException("Configured Lunar model [{$key}] is invalid.");
        }

        if (! is_subclass_of($class, Model::class)) {
            throw new XeroConfigurationException("Configured Lunar model [{$key}] must extend Eloquent Model.");
        }

        return $class;
    }
}
