<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

use Illuminate\Database\Eloquent\Model;

class OrderInvoiceSyncEligibility
{
    public function allows(Model $order): bool
    {
        return ! config('lunarpanel-xero.orders.require_placed_for_sync', false)
            || filled($order->getAttribute('placed_at'));
    }

    public function becamePlaced(Model $order): bool
    {
        return (bool) config('lunarpanel-xero.orders.require_placed_for_sync', false)
            && $order->wasChanged('placed_at')
            && blank($order->getOriginal('placed_at'))
            && filled($order->getAttribute('placed_at'));
    }
}
