<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Models;

use CharlieLangridge\LunarXero\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $singleton_key
 * @property InvoiceStatus|string $invoice_status
 * @property string|null $active_tenant_id
 * @property string|null $default_account_code
 * @property array<string, mixed>|null $connection_meta
 */
class XeroSettings extends Model
{
    protected $guarded = [];

    protected $casts = [
        'connection_meta' => 'array',
        'last_successful_sync_at' => 'immutable_datetime',
        'invoice_status' => InvoiceStatus::class,
    ];

    public function getTable(): string
    {
        return config('lunarpanel-xero.tables.settings', parent::getTable());
    }
}
