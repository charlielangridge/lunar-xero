<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $table = 'test_product_variants';

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getDescription(): string
    {
        return (string) ($this->product?->attribute_data['name'] ?? '');
    }

    public function getOption(): string
    {
        return (string) ($this->option_values ?? '');
    }
}
