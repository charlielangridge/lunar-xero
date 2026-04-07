<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

class XeroUrlFactory
{
    public static function invoiceUrl(?string $invoiceId): ?string
    {
        if (! filled($invoiceId)) {
            return null;
        }

        return 'https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID='.urlencode((string) $invoiceId);
    }
}
