<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Models;

use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property SyncOperation|string $operation
 * @property SyncStatus|string $status
 * @property string|null $resource_type
 * @property int|string|null $resource_id
 * @property string|null $error_message
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $response
 * @property array<string, mixed>|null $context
 */
class XeroSyncLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'operation' => SyncOperation::class,
        'status' => SyncStatus::class,
        'payload' => 'array',
        'response' => 'array',
        'context' => 'array',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return config('lunarpanel-xero.tables.sync_logs', parent::getTable());
    }
}
