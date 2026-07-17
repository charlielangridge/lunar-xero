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

        if ($product && filled($product->xero_item_code)) {
            if (filled($variant->xero_item_code) && XeroItemCode::isGeneratedForSku($variant->xero_item_code, $variant->sku ?? null)) {
                $variant->xero_item_code = null;
            }

            return;
        }

        if (filled($variant->xero_item_code)) {
            $itemCode = $this->explicitCode((string) $variant->xero_item_code);

            if ($variant->isDirty('sku') && XeroItemCode::isGeneratedForSku($itemCode, $variant->getOriginal('sku'))) {
                $variant->xero_item_code = XeroItemCode::fallbackForSku((string) $variant->sku);

                return;
            }

            $variant->xero_item_code = $itemCode;

            return;
        }

        if (filled($variant->sku) && XeroItemCode::shouldGenerateForSku((string) $variant->sku)) {
            $variant->xero_item_code = XeroItemCode::generatedForSku((string) $variant->sku);
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
