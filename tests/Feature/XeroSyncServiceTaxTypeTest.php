<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroSyncService;
use CharlieLangridge\LunarXero\Support\LunarModelResolver;

it('maps zero rated order lines to the zero rated xero tax type', function (): void {
    $line = (object) [
        'tax_breakdown' => (object) [
            'amounts' => collect([
                (object) ['percentage' => 0.0],
            ]),
        ],
        'tax_total' => 0.0,
    ];

    $service = new class(Mockery::mock(XeroClientInterface::class), app(XeroSettingsRepository::class), app(LunarModelResolver::class)) extends XeroSyncService
    {
        public function taxTypeFor(object $line): ?string
        {
            return $this->resolveLineTaxType($line);
        }
    };

    expect($service->taxTypeFor($line))->toBe('ZERORATEDOUTPUT');
});

it('maps standard vat order lines to the standard xero output tax type', function (): void {
    $line = (object) [
        'tax_breakdown' => (object) [
            'amounts' => collect([
                (object) ['percentage' => 20.0],
            ]),
        ],
        'tax_total' => 2.0,
    ];

    $service = new class(Mockery::mock(XeroClientInterface::class), app(XeroSettingsRepository::class), app(LunarModelResolver::class)) extends XeroSyncService
    {
        public function taxTypeFor(object $line): ?string
        {
            return $this->resolveLineTaxType($line);
        }
    };

    expect($service->taxTypeFor($line))->toBe('OUTPUT2');
});
