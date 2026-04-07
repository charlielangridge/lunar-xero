<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Data;

final class InvoiceLineData
{
    public function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unitAmount,
        public readonly string $accountCode,
        public readonly ?string $itemCode = null,
        public readonly ?string $taxType = null,
    ) {}

    /**
     * @return array{description:string,quantity:float,unit_amount:float,account_code:string,item_code:?string,tax_type:?string}
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount,
            'account_code' => $this->accountCode,
            'item_code' => $this->itemCode,
            'tax_type' => $this->taxType,
        ];
    }
}
