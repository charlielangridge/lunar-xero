<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $table = 'test_customers';

    protected $guarded = [];

    protected $casts = [
        'xero_include_order_line_notes' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
