<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Jobs\SyncOrderInvoiceToXero;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use Illuminate\Support\Facades\Queue;

it('queues an invoice sync as soon as an order is created', function (): void {
    config()->set('lunarpanel-xero.defaults.sync_queue', 'xero');

    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-CREATED-1',
    ]);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, function (SyncOrderInvoiceToXero $job) use ($order): bool {
        return $job->orderId === $order->id
            && $job->queue === 'xero';
    });

    expect(XeroSyncLog::query()->where([
        'operation' => 'invoice',
        'resource_type' => $order::class,
        'resource_id' => $order->id,
        'status' => 'pending',
    ])->exists())->toBeTrue();
});

it('queues an invoice resync when the customer reference changes on a synced order', function (): void {
    config()->set('lunarpanel-xero.defaults.sync_queue', 'xero');

    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-1',
        'customer_reference' => 'PO-100',
        'xero_invoice_id' => 'invoice-1',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $order->forceFill(['customer_reference' => 'PO-200'])->save();

    Queue::assertPushed(SyncOrderInvoiceToXero::class, function (SyncOrderInvoiceToXero $job) use ($order): bool {
        return $job->orderId === $order->id
            && $job->queue === 'xero';
    });
});

it('does not queue an invoice resync when the customer reference changes on an unsynced order', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-2',
        'customer_reference' => 'PO-100',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $order->forceFill(['customer_reference' => 'PO-200'])->save();

    Queue::assertNotPushed(SyncOrderInvoiceToXero::class);
});

it('queues an invoice resync when the order reference changes on a synced order', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-3',
        'customer_reference' => 'PO-100',
        'xero_invoice_id' => 'invoice-3',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $order->forceFill(['reference' => 'ORDER-3-UPDATED'])->save();

    Queue::assertPushed(SyncOrderInvoiceToXero::class, function (SyncOrderInvoiceToXero $job) use ($order): bool {
        return $job->orderId === $order->id;
    });
});

it('queues an invoice resync when the purchase order reference changes on a synced order', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-PO-1',
        'customer_reference' => 'CUSTOMER-PO-1',
        'meta' => ['purchase_order' => 'PO-100'],
        'xero_invoice_id' => 'invoice-po-1',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $order->forceFill(['meta' => ['purchase_order' => 'PO-200']])->save();

    Queue::assertPushed(SyncOrderInvoiceToXero::class, function (SyncOrderInvoiceToXero $job) use ($order): bool {
        return $job->orderId === $order->id;
    });
});

it('does not queue an invoice resync when unrelated order fields change', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-4',
        'customer_reference' => 'PO-100',
        'xero_invoice_id' => 'invoice-4',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $order->forceFill(['xero_invoice_id' => 'invoice-4'])->save();

    Queue::assertNotPushed(SyncOrderInvoiceToXero::class);
});

it('does not queue an invoice resync when unrelated order meta changes', function (): void {
    Queue::fake();

    $order = Order::query()->create([
        'reference' => 'ORDER-5',
        'customer_reference' => 'PO-100',
        'meta' => ['purchase_order' => 'PO-100', 'note' => 'before'],
        'xero_invoice_id' => 'invoice-5',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $order->forceFill(['meta' => ['purchase_order' => 'PO-100', 'note' => 'after']])->save();

    Queue::assertNotPushed(SyncOrderInvoiceToXero::class);
});
