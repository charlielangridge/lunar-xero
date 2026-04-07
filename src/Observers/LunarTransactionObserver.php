<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Observers;

use CharlieLangridge\LunarXero\Jobs\SyncPaymentToXero;
use Illuminate\Database\Eloquent\Model;

class LunarTransactionObserver
{
    public function created(Model $transaction): void
    {
        if (! $this->shouldQueue($transaction, false)) {
            return;
        }

        $this->queuePaymentSync($transaction);
    }

    public function updated(Model $transaction): void
    {
        if (! $this->shouldQueue($transaction, true)) {
            return;
        }

        $this->queuePaymentSync($transaction);
    }

    protected function shouldQueue(Model $transaction, bool $requiresChange): bool
    {
        $order = $transaction->order ?? null;

        if (! $order instanceof Model || ! filled($order->xero_invoice_id)) {
            return false;
        }

        if (isset($transaction->success) && ! (bool) $transaction->success) {
            return false;
        }

        $type = strtolower(trim((string) ($transaction->type ?? '')));

        if ($type === 'refund') {
            if (! $requiresChange) {
                return true;
            }

            return $transaction->wasChanged('amount')
                || $transaction->wasChanged('success')
                || $transaction->wasChanged('type')
                || $transaction->wasChanged('status');
        }

        if ($type === 'intent') {
            return false;
        }

        $hasCapturedAt = filled($transaction->captured_at);
        $looksCaptured = $hasCapturedAt || $type === 'capture';

        if (! $looksCaptured) {
            return false;
        }

        if (! $requiresChange) {
            return true;
        }

        return $transaction->wasChanged('captured_at')
            || $transaction->wasChanged('success')
            || $transaction->wasChanged('type')
            || $transaction->wasChanged('status');
    }

    protected function queuePaymentSync(Model $transaction): void
    {
        SyncPaymentToXero::dispatch($transaction->getKey(), $transaction::class)
            ->onQueue(config('lunarpanel-xero.defaults.sync_queue', 'default'));
    }
}
