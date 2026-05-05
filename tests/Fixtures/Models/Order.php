<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $table = 'test_orders';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'billing');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
