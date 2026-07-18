<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Observers;

use CharlieLangridge\LunarXero\Support\XeroItemCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LunarProductObserver
{
    public function saving(Model $product): void
    {
        $itemCode = $product->getAttribute('xero_item_code');

        if (! filled($itemCode)) {
            return;
        }

        $product->setAttribute('xero_item_code', $this->explicitCode((string) $itemCode));
    }

    public function saved(Model $product): void
    {
        if (! filled($product->getAttribute('xero_item_code')) || ! method_exists($product, 'variants')) {
            return;
        }

        $product->variants()
            ->whereNotNull('xero_item_code')
            ->get()
            ->each(function (Model $variant): void {
                $variantItemCode = $variant->getAttribute('xero_item_code');
                $variantSku = $variant->getAttribute('sku');

                if (! XeroItemCode::isGeneratedForSku((string) $variantItemCode, $variantSku === null ? null : (string) $variantSku)) {
                    return;
                }

                $variant->forceFill(['xero_item_code' => null])->saveQuietly();
            });
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
