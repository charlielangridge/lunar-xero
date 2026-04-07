<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'test_products';

    protected $guarded = [];

    protected $casts = [
        'attribute_data' => 'array',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
