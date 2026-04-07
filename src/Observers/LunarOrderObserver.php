<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Observers;

use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Jobs\SyncOrderInvoiceToXero;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use Illuminate\Database\Eloquent\Model;

class LunarOrderObserver
{
    public function created(Model $order): void
    {
        XeroSyncLog::query()->create([
            'operation' => SyncOperation::Invoice->value,
            'status' => SyncStatus::Pending->value,
            'resource_type' => $order::class,
            'resource_id' => $order->getKey(),
            'payload' => ['order_id' => $order->getKey(), 'source' => 'model_observer'],
            'attempt' => 0,
            'started_at' => now(),
        ]);

        SyncOrderInvoiceToXero::dispatch($order->getKey())
            ->onQueue(config('lunarpanel-xero.defaults.sync_queue', 'default'));
    }

    public function updated(Model $order): void
    {
        if (! $this->shouldResyncInvoiceReference($order)) {
            return;
        }

        if (! filled($order->xero_invoice_id)) {
            return;
        }

        SyncOrderInvoiceToXero::dispatch($order->getKey())
            ->onQueue(config('lunarpanel-xero.defaults.sync_queue', 'default'));
    }

    protected function shouldResyncInvoiceReference(Model $order): bool
    {
        return $order->wasChanged('customer_reference')
            || $order->wasChanged('reference');
    }
}
