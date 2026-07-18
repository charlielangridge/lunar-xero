<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Commands;

use CharlieLangridge\LunarXero\Support\LunarModelResolver;
use CharlieLangridge\LunarXero\Support\XeroItemCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class BackfillXeroItemCodes extends Command
{
    protected $signature = 'xero:backfill-item-codes {--dry-run : Report changes without writing them} {--chunk=500 : Number of variants to process per batch}';

    protected $description = 'Generate Xero-safe item codes for long SKU variants and validate explicit Xero item codes.';

    public function handle(LunarModelResolver $modelResolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $productModel = $modelResolver->productModel();
        $variantModel = $modelResolver->variantModel();
        $created = 0;
        $cleared = 0;
        $invalid = 0;

        $productModel::query()
            ->whereNotNull('xero_item_code')
            ->cursor()
            ->each(function (Model $product) use (&$invalid): void {
                try {
                    XeroItemCode::explicit((string) $product->getAttribute('xero_item_code'));
                } catch (InvalidArgumentException $exception) {
                    $invalid++;
                    $this->error("Product [{$product->getKey()}] has an invalid Xero item code: {$exception->getMessage()}");
                }
            });

        $variantModel::query()
            ->with('product')
            ->chunkById($chunkSize, function ($variants) use ($dryRun, &$created, &$cleared, &$invalid): void {
                foreach ($variants as $variant) {
                    $product = $variant->product ?? null;
                    $productItemCode = $product instanceof Model ? $product->getAttribute('xero_item_code') : null;
                    $variantItemCode = $variant->getAttribute('xero_item_code');
                    $variantSku = $variant->getAttribute('sku');

                    if ($product instanceof Model && filled($productItemCode)) {
                        if (filled($variantItemCode) && XeroItemCode::isGeneratedForSku((string) $variantItemCode, $variantSku === null ? null : (string) $variantSku)) {
                            $cleared++;

                            if (! $dryRun) {
                                $variant->forceFill(['xero_item_code' => null])->saveQuietly();
                            }
                        } elseif (filled($variantItemCode)) {
                            $invalid += $this->validateVariantCode($variant);
                        }

                        continue;
                    }

                    if (filled($variantItemCode)) {
                        $invalid += $this->validateVariantCode($variant);

                        continue;
                    }

                    if (! filled($variantSku) || ! XeroItemCode::shouldGenerateForSku((string) $variantSku)) {
                        continue;
                    }

                    $created++;

                    if (! $dryRun) {
                        $variant->forceFill([
                            'xero_item_code' => XeroItemCode::generatedForSku((string) $variantSku),
                        ])->saveQuietly();
                    }
                }
            });

        $this->info("Generated {$created} variant Xero item codes.");
        $this->info("Cleared {$cleared} generated variant Xero item codes for shared product item codes.");

        if ($invalid > 0) {
            $this->error("Found {$invalid} invalid explicit Xero item codes.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function validateVariantCode(Model $variant): int
    {
        try {
            XeroItemCode::explicit((string) $variant->getAttribute('xero_item_code'));

            return 0;
        } catch (InvalidArgumentException $exception) {
            $this->error("Variant [{$variant->getKey()}] has an invalid Xero item code: {$exception->getMessage()}");

            return 1;
        }
    }
}
