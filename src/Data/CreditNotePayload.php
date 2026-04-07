<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Data;

use Carbon\CarbonInterface;

final class CreditNotePayload
{
    /**
     * @param  array<int, InvoiceLineData>  $lines
     */
    public function __construct(
        public readonly string $contactId,
        public readonly string $reference,
        public readonly array $lines,
        public readonly CarbonInterface $date,
        public readonly string $status = 'AUTHORISED',
        public readonly string $type = 'ACCRECCREDIT',
    ) {}
}
