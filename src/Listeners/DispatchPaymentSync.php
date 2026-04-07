<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Listeners;

use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Jobs\SyncPaymentToXero;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;

class DispatchPaymentSync
{
    public function handle(object $event): void
    {
        $payment = $event->payment ?? $event->transaction ?? $event->model ?? null;

        if (! $payment || ! method_exists($payment, 'getKey')) {
            return;
        }

        XeroSyncLog::query()->create([
            'operation' => SyncOperation::Payment->value,
            'status' => SyncStatus::Pending->value,
            'resource_type' => $payment::class,
            'resource_id' => $payment->getKey(),
            'external_reference' => sprintf('%s:%s', $payment::class, $payment->getKey()),
            'payload' => ['payment_id' => $payment->getKey(), 'source' => 'event_listener'],
            'attempt' => 0,
            'started_at' => now(),
        ]);

        SyncPaymentToXero::dispatch($payment->getKey(), $payment::class)
            ->onQueue(config('lunarpanel-xero.defaults.sync_queue', 'default'));
    }
}
