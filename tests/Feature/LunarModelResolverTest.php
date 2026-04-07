<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Exceptions\XeroConfigurationException;
use CharlieLangridge\LunarXero\Support\LunarModelResolver;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Customer;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;

it('resolves the configured lunar models', function (): void {
    $resolver = app(LunarModelResolver::class);

    expect($resolver->customerModel())->toBe(Customer::class)
        ->and($resolver->orderModel())->toBe(Order::class);
});

it('throws when a configured lunar model does not exist', function (): void {
    config()->set('lunarpanel-xero.models.customer', 'App\\MissingCustomer');

    expect(fn () => app(LunarModelResolver::class)->customerModel())
        ->toThrow(XeroConfigurationException::class, 'Configured Lunar model [customer] is invalid.');
});

it('throws when a configured lunar model is not an eloquent model', function (): void {
    config()->set('lunarpanel-xero.models.customer', stdClass::class);

    expect(fn () => app(LunarModelResolver::class)->customerModel())
        ->toThrow(XeroConfigurationException::class, 'Configured Lunar model [customer] must extend Eloquent Model.');
});
