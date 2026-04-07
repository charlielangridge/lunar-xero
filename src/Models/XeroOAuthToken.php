<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $singleton_key
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $id_token
 * @property array<int, string>|null $scopes
 */
class XeroOAuthToken extends Model
{
    protected $guarded = [];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'id_token' => 'encrypted',
        'scopes' => 'array',
        'expires_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return config('lunarpanel-xero.tables.oauth_tokens', parent::getTable());
    }
}
