<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Listeners;

use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Jobs\SyncOrderInvoiceToXero;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use CharlieLangridge\LunarXero\Support\OrderInvoiceSyncEligibility;
use Illuminate\Database\Eloquent\Model;

class DispatchOrderInvoiceSync
{
    public function handle(object $event): void
    {
        $order = $event->order ?? $event->model ?? null;

        if (! $order instanceof Model || ! app(OrderInvoiceSyncEligibility::class)->allows($order)) {
            return;
        }

        // Suppress a second creation trigger only until invoice sync has actually
        // been attempted. A later configured event can request another sync.
        $observerQueued = XeroSyncLog::query()->where('operation', SyncOperation::Invoice->value)
            ->where('resource_type', $order::class)
            ->where('resource_id', $order->getKey())
            ->where('payload->source', 'model_observer')
            ->exists();
        $syncAttempted = XeroSyncLog::query()->where('operation', SyncOperation::Invoice->value)
            ->where('resource_type', $order::class)
            ->where('resource_id', $order->getKey())
            ->where('attempt', '>', 0)
            ->exists();

        if ($observerQueued && ! $syncAttempted) {
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
