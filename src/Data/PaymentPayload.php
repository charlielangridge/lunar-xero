<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Data;

use Carbon\CarbonInterface;

final class PaymentPayload
{
    public function __construct(
        public readonly ?string $invoiceId,
        public readonly ?string $creditNoteId,
        public readonly string $accountCode,
        public readonly float $amount,
        public readonly CarbonInterface $date,
        public readonly string $reference,
    ) {}
}
