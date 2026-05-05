<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

class XeroUrlFactory
{
    /**
     * @param  array<int, string>  $allowedStatuses
     */
    public static function customerInvoiceUrl(?string $onlineInvoiceUrl, ?string $invoiceStatus, array $allowedStatuses = ['AUTHORISED', 'PAID']): ?string
    {
        if (! filled($onlineInvoiceUrl) || ! filled($invoiceStatus)) {
            return null;
        }

        $normalizedStatus = mb_strtoupper(trim((string) $invoiceStatus));
        $normalizedAllowedStatuses = array_map(
            fn (string $status): string => mb_strtoupper(trim($status)),
            $allowedStatuses,
        );

        if (! in_array($normalizedStatus, $normalizedAllowedStatuses, true)) {
            return null;
        }

        return (string) $onlineInvoiceUrl;
    }

    public static function invoiceUrl(?string $invoiceId): ?string
    {
        if (! filled($invoiceId)) {
            return null;
        }

        return 'https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID='.urlencode((string) $invoiceId);
    }
}
