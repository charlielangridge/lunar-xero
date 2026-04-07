<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Data\AccountOption;
use CharlieLangridge\LunarXero\Exceptions\XeroTransportException;
use CharlieLangridge\LunarXero\Support\XeroAccountOptions;

it('filters invoice account options to revenue and sales accounts', function (): void {
    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getAccounts')->once()->withNoArgs()->andReturn(collect([
        new AccountOption(code: '200', name: 'Sales', type: 'SALES'),
        new AccountOption(code: '201', name: 'Revenue', class: 'REVENUE'),
        new AccountOption(code: '090', name: 'Bank', type: 'BANK'),
    ]));
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroAccountOptions::class);

    $options = app(XeroAccountOptions::class)->invoiceOptions();

    expect($options)->toBe([
        '200' => '200 - Sales',
        '201' => '201 - Revenue',
    ]);
});

it('filters payment account options and can search their labels', function (): void {
    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getAccounts')->once()->with(true)->andReturn(collect([
        new AccountOption(code: '090', name: 'Stripe Clearing', type: 'BANK', enablePaymentsToAccount: true),
        new AccountOption(code: '091', name: 'Cash Drawer', type: 'BANK', enablePaymentsToAccount: true),
    ]));
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroAccountOptions::class);

    $options = app(XeroAccountOptions::class);

    expect($options->options(true))->toBe([
        '090' => '090 - Stripe Clearing',
        '091' => '091 - Cash Drawer',
    ])
        ->and($options->search('stripe', true))->toBe([
            '090' => '090 - Stripe Clearing',
        ])
        ->and($options->label('090', true))->toBe('090 - Stripe Clearing');
});

it('returns empty account options when the xero client throws a package exception', function (): void {
    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getAccounts')->twice()->withNoArgs()->andThrow(new XeroTransportException('Unable to fetch accounts.'));
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroAccountOptions::class);

    $options = app(XeroAccountOptions::class);

    expect($options->invoiceOptions())->toBe([])
        ->and($options->invoiceLabel('200'))->toBe('200');
});
