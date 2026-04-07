<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Enums;

enum InvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Authorised = 'AUTHORISED';
}
