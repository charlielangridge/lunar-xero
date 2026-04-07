<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $payment_type
 * @property string $account_code
 * @property string|null $account_name
 */
class XeroPaymentTypeMapping extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('lunarpanel-xero.tables.payment_type_mappings', parent::getTable());
    }
}
