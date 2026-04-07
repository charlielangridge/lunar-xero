<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Listeners;

use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Jobs\SyncOrderInvoiceToXero;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;

class DispatchOrderInvoiceSync
{
    public function handle(object $event): void
    {
        $order = $event->order ?? $event->model ?? null;

        if (! $order || ! method_exists($order, 'getKey')) {
            return;
        }

        XeroSyncLog::query()->create([
            'operation' => SyncOperation::Invoice->value,
            'status' => SyncStatus::Pending->value,
            'resource_type' => $order::class,
            'resource_id' => $order->getKey(),
            'payload' => ['order_id' => $order->getKey(), 'source' => 'event_listener'],
            'attempt' => 0,
            'started_at' => now(),
        ]);

        SyncOrderInvoiceToXero::dispatch($order->getKey())
            ->onQueue(config('lunarpanel-xero.defaults.sync_queue', 'default'));
    }
}
