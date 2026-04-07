<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Data;

final class InvoicePayload
{
    /**
     * @param  array<int, InvoiceLineData>  $lines
     */
    public function __construct(
        public readonly string $contactId,
        public readonly string $status,
        public readonly string $reference,
        public readonly array $lines,
        public readonly string $type = 'ACCREC',
    ) {}
}
