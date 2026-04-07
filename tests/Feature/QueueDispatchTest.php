<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Jobs\SyncOrderInvoiceToXero;
use CharlieLangridge\LunarXero\Jobs\SyncPaymentToXero;
use CharlieLangridge\LunarXero\Listeners\DispatchOrderInvoiceSync;
use CharlieLangridge\LunarXero\Listeners\DispatchPaymentSync;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Payment;
use Illuminate\Support\Facades\Queue;

it('dispatches order invoice syncs onto the configured xero queue', function (): void {
    config()->set('lunarpanel-xero.defaults.sync_queue', 'xero');

    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-QUEUE-1',
    ]);

    app(DispatchOrderInvoiceSync::class)->handle((object) [
        'order' => $order,
    ]);

    Queue::assertPushedOn('xero', SyncOrderInvoiceToXero::class);
});

it('dispatches payment syncs onto the configured xero queue', function (): void {
    config()->set('lunarpanel-xero.defaults.sync_queue', 'xero');

    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-QUEUE-2',
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'reference' => 'PAYMENT-QUEUE-1',
    ]);

    app(DispatchPaymentSync::class)->handle((object) [
        'payment' => $payment,
    ]);

    Queue::assertPushedOn('xero', SyncPaymentToXero::class);
});
