<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Jobs\SyncPaymentToXero;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Payment;
use Illuminate\Support\Facades\Queue;

it('queues a payment sync when a captured stripe transaction is created for a synced order', function (): void {
    config()->set('lunarpanel-xero.defaults.sync_queue', 'xero');

    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-STRIPE-1',
        'xero_invoice_id' => 'invoice-1',
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'capture',
        'driver' => 'stripe',
        'amount' => 50,
        'reference' => 'ch_123',
        'captured_at' => now(),
        'success' => true,
    ]);

    Queue::assertPushed(SyncPaymentToXero::class, function (SyncPaymentToXero $job) use ($payment): bool {
        return $job->paymentId === $payment->id
            && $job->paymentClass === Payment::class
            && $job->queue === 'xero';
    });
});

it('does not queue a payment sync when a stripe transaction is created before the xero invoice exists', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-STRIPE-2',
    ]);

    Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'capture',
        'driver' => 'stripe',
        'amount' => 50,
        'reference' => 'ch_456',
        'captured_at' => now(),
        'success' => true,
    ]);

    Queue::assertNotPushed(SyncPaymentToXero::class);
});

it('does not queue a payment sync for non-captured intent transactions', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-STRIPE-3',
        'xero_invoice_id' => 'invoice-3',
    ]);

    Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'intent',
        'driver' => 'stripe',
        'amount' => 50,
        'reference' => 'pi_123',
        'success' => true,
    ]);

    Queue::assertNotPushed(SyncPaymentToXero::class);
});

it('queues a refund sync when a refund transaction is created for a synced order', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-REFUND-1',
        'xero_invoice_id' => 'invoice-refund-1',
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'refund',
        'driver' => 'stripe',
        'amount' => -25,
        'reference' => 're_123',
        'success' => true,
    ]);

    Queue::assertPushed(SyncPaymentToXero::class, function (SyncPaymentToXero $job) use ($payment): bool {
        return $job->paymentId === $payment->id
            && $job->paymentClass === Payment::class;
    });
});

it('queues a payment sync when a transaction is updated to captured on a synced order', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-STRIPE-4',
        'xero_invoice_id' => 'invoice-4',
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'intent',
        'driver' => 'stripe',
        'amount' => 50,
        'reference' => 'ch_789',
        'success' => true,
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $payment->forceFill([
        'type' => 'capture',
        'captured_at' => now(),
    ])->save();

    Queue::assertPushed(SyncPaymentToXero::class, function (SyncPaymentToXero $job) use ($payment): bool {
        return $job->paymentId === $payment->id
            && $job->paymentClass === Payment::class;
    });
});
