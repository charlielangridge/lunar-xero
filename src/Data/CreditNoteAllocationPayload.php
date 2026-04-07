<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Data;

use Carbon\CarbonInterface;

final class CreditNoteAllocationPayload
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly float $amount,
        public readonly CarbonInterface $date,
    ) {}
}
