<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
