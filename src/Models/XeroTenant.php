<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $tenant_name
 * @property bool $is_active
 * @property array<string, mixed>|null $payload
 */
class XeroTenant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'bool',
        'payload' => 'array',
        'connected_at' => 'immutable_datetime',
        'updated_at_xero' => 'immutable_datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getTable(): string
    {
        return config('lunarpanel-xero.tables.tenants', parent::getTable());
    }
}
