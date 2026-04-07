<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroSyncService;
use CharlieLangridge\LunarXero\Support\LunarModelResolver;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Product;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\ProductVariant;
use Illuminate\Database\Eloquent\Relations\Relation;

it('resolves product variant morph aliases when building xero invoice lines', function (): void {
    Relation::morphMap([
        'product_variant' => ProductVariant::class,
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Mapped Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'MAPPED-SKU-1',
        'option_values' => 'A5',
        'xero_account_code' => '201',
    ]);

    $line = new class($variant)
    {
        public string $purchasable_type = 'product_variant';

        public string $description = 'Mapped line';

        public int $quantity = 1;

        public float $unit_price = 12.5;

        public function __construct(
            public ProductVariant $purchasable,
        ) {}
    };

    $client = Mockery::mock(XeroClientInterface::class);
    $client->shouldReceive('findOrCreateItem')->once()->with(Mockery::on(function (array $payload): bool {
        return $payload['item_code'] === 'MAPPED-SKU-1'
            && $payload['name'] === 'Mapped Product - A5'
            && $payload['description'] === 'Mapped Product - A5';
    }))->andReturn(['item_code' => 'MAPPED-SKU-1']);

    $service = new class($client, app(XeroSettingsRepository::class), app(LunarModelResolver::class)) extends XeroSyncService
    {
        public function buildLinesFromArray(array $lines): array
        {
            return collect($lines)->map(function ($line) {
                [$variant, $product] = $this->resolveLineContext($line);

                return [
                    'description' => $this->resolveInvoiceLineDescription($line, $variant, $product),
                    'account_code' => $this->resolveAccountCode($variant, $product),
                    'item_code' => $this->resolveItemCode($line, $variant, $product),
                ];
            })->all();
        }
    };

    $lines = $service->buildLinesFromArray([$line]);

    expect($lines[0])->toBe([
        'description' => 'Mapped Product - A5',
        'account_code' => '201',
        'item_code' => 'MAPPED-SKU-1',
    ]);
});
