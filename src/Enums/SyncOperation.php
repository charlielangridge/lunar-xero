<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Enums;

enum SyncOperation: string
{
    case Invoice = 'invoice';
    case Payment = 'payment';
    case CreditNote = 'credit_note';
    case Contact = 'contact';
    case InvoiceEmail = 'invoice_email';
    case Item = 'item';
}
