<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Observers;

use CharlieLangridge\LunarXero\Support\XeroItemCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LunarProductVariantObserver
{
    public function saving(Model $variant): void
    {
        $product = $this->productFor($variant);
        $variantItemCode = $variant->getAttribute('xero_item_code');
        $variantSku = $variant->getAttribute('sku');
        $productItemCode = $product?->getAttribute('xero_item_code');

        if ($product && filled($productItemCode)) {
            if (filled($variantItemCode) && XeroItemCode::isGeneratedForSku((string) $variantItemCode, $variantSku === null ? null : (string) $variantSku)) {
                $variant->setAttribute('xero_item_code', null);
            }

            return;
        }

        if (filled($variantItemCode)) {
            $itemCode = $this->explicitCode((string) $variantItemCode);

            if ($variant->isDirty('sku') && XeroItemCode::isGeneratedForSku($itemCode, $variant->getOriginal('sku'))) {
                $variant->setAttribute('xero_item_code', XeroItemCode::fallbackForSku((string) $variantSku));

                return;
            }

            $variant->setAttribute('xero_item_code', $itemCode);

            return;
        }

        if (filled($variantSku) && XeroItemCode::shouldGenerateForSku((string) $variantSku)) {
            $variant->setAttribute('xero_item_code', XeroItemCode::generatedForSku((string) $variantSku));
        }
    }

    private function productFor(Model $variant): ?Model
    {
        if (($variant->product ?? null) instanceof Model) {
            return $variant->product;
        }

        if (method_exists($variant, 'product')) {
            return $variant->product()->first();
        }

        return null;
    }

    private function explicitCode(string $itemCode): string
    {
        try {
            return XeroItemCode::explicit($itemCode);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'xero_item_code' => $exception->getMessage(),
            ]);
        }
    }
}
