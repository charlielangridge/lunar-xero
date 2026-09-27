<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Jobs\SyncOrderInvoiceToXero;
use CharlieLangridge\LunarXero\Jobs\SyncPaymentToXero;
use CharlieLangridge\LunarXero\Listeners\DispatchOrderInvoiceSync;
use CharlieLangridge\LunarXero\Listeners\DispatchPaymentSync;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use CharlieLangridge\LunarXero\Observers\LunarOrderObserver;
use CharlieLangridge\LunarXero\Services\XeroSyncService;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Customer;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\OrderLine;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Payment;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\Queue;

it('keeps legacy creation dispatch when the option is missing or false', function (?bool $setting): void {
    if ($setting === null) {
        config()->offsetUnset('lunarpanel-xero.orders.require_placed_for_sync');
    } else {
        config()->set('lunarpanel-xero.orders.require_placed_for_sync', $setting);
    }

    Queue::fake();
    Order::query()->create(['reference' => 'DRAFT']);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, 1);
})->with([null, false]);

it('does not dispatch or leave a pending invoice log for a draft in placed mode', function (): void {
    config()->set('lunarpanel-xero.orders.require_placed_for_sync', true);
    Queue::fake();

    $order = Order::query()->create(['reference' => 'DRAFT']);
    $order->update(['reference' => 'DRAFT-EDITED']);

    Queue::assertNotPushed(SyncOrderInvoiceToXero::class);
    expect(XeroSyncLog::query()->where('resource_id', $order->id)->exists())->toBeFalse();
});

it('queues exactly once when a draft becomes placed', function (): void {
    config()->set('lunarpanel-xero.orders.require_placed_for_sync', true);
    Queue::fake();

    $order = Order::query()->create(['reference' => 'DRAFT']);
    $order->update(['placed_at' => now()]);
    $order->update(['reference' => 'PLACED-EDITED']);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, 1);
});

it('queues an order created already placed', function (): void {
    config()->set('lunarpanel-xero.orders.require_placed_for_sync', true);
    Queue::fake();

    Order::query()->create(['reference' => 'PLACED', 'placed_at' => now()]);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, 1);
});

it('does not double dispatch when a configured created event follows the model observer', function (): void {
    Queue::fake();
    $order = Order::query()->create(['reference' => 'EVENT']);

    app(DispatchOrderInvoiceSync::class)->handle((object) ['order' => $order]);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, 1);
});

it('retains the configured event hook for an order without an observer dispatch', function (): void {
    Queue::fake();
    $order = Order::withoutEvents(fn () => Order::query()->create(['reference' => 'EVENT-ONLY']));

    app(DispatchOrderInvoiceSync::class)->handle((object) ['order' => $order]);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, 1);
});

it('retains the configured event hook for a later order event', function (): void {
    Queue::fake();
    $order = Order::query()->create(['reference' => 'LATER-EVENT']);

    XeroSyncLog::query()->create([
        'operation' => 'invoice',
        'status' => 'failed',
        'resource_type' => $order::class,
        'resource_id' => $order->id,
        'attempt' => 1,
    ]);

    app(DispatchOrderInvoiceSync::class)->handle((object) ['order' => $order]);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, 2);
});

it('deduplicates a configured creation event that fires before the model observer', function (): void {
    Queue::fake();
    $order = Order::withoutEvents(fn () => Order::query()->create(['reference' => 'EVENT-FIRST']));

    app(DispatchOrderInvoiceSync::class)->handle((object) ['order' => $order]);
    app(LunarOrderObserver::class)->created($order);

    Queue::assertPushed(SyncOrderInvoiceToXero::class, 1);
});

it('skips direct invoice and email sync for an unplaced custom order without provider calls', function (): void {
    config()->set('lunarpanel-xero.orders.require_placed_for_sync', true);
    Queue::fake();
    $order = Order::query()->create(['reference' => 'DRAFT']);
    $client = Mockery::mock(XeroClientInterface::class);
    $client->shouldNotReceive('createInvoice');
    $client->shouldNotReceive('createContact');
    app()->instance(XeroClientInterface::class, $client);
    app()->forgetInstance(XeroSyncService::class);

    $service = app(XeroSyncService::class);
    $invoice = $service->syncOrderInvoiceById($order->id);
    $email = $service->syncAndEmailOrderInvoiceById($order->id);

    expect($invoice['reason'])->toBe('order_not_placed')
        ->and($email['reason'])->toBe('order_not_placed')
        ->and($order->fresh()->xero_invoice_id)->toBeNull()
        ->and(XeroSyncLog::query()->where('status', 'failed')->exists())->toBeFalse()
        ->and(XeroSyncLog::query()->where('status', 'pending')->exists())->toBeFalse();
});

it('does not queue payment work for a draft even if it has a legacy invoice id', function (): void {
    config()->set('lunarpanel-xero.orders.require_placed_for_sync', true);
    Queue::fake();
    $order = Order::query()->create(['reference' => 'DRAFT', 'xero_invoice_id' => 'old-id']);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'capture',
        'success' => true,
        'amount' => 50,
        'captured_at' => now(),
    ]);

    app(DispatchPaymentSync::class)->handle((object) ['payment' => $payment]);

    Queue::assertNotPushed(SyncPaymentToXero::class);
    expect(XeroSyncLog::query()->where('operation', 'payment')->exists())->toBeFalse();
});

it('skips direct capture and refund sync for an unplaced order', function (): void {
    config()->set('lunarpanel-xero.orders.require_placed_for_sync', true);
    Queue::fake();
    $order = Order::query()->create(['reference' => 'DRAFT', 'xero_invoice_id' => 'old-id']);
    $capture = Payment::query()->create(['order_id' => $order->id, 'type' => 'capture', 'success' => true, 'amount' => 50]);
    $refund = Payment::query()->create(['order_id' => $order->id, 'type' => 'refund', 'success' => true, 'amount' => -10]);
    $client = Mockery::mock(XeroClientInterface::class);
    $client->shouldNotReceive('createPayment');
    $client->shouldNotReceive('createCreditNote');
    app()->instance(XeroClientInterface::class, $client);
    app()->forgetInstance(XeroSyncService::class);

    $service = app(XeroSyncService::class);
    $captureResult = $service->syncPaymentById($capture->id, Payment::class);
    $refundResult = $service->syncPaymentById($refund->id, Payment::class);

    expect($captureResult['reason'])->toBe('order_not_placed')
        ->and($refundResult['reason'])->toBe('order_not_placed');
});

it('reloads a stale order before deciding whether to create an invoice', function (): void {
    Queue::fake();
    $customer = Customer::query()->create(['email' => 'buyer@example.com', 'xero_contact_id' => 'contact-1']);
    $product = Product::query()->create(['xero_account_code' => '200', 'xero_item_code' => 'RACE-ITEM', 'attribute_data' => ['name' => 'Product']]);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'RACE']);
    OrderLine::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'description' => 'Line', 'unit_price' => 10]);
    $staleOrder = $order->fresh();

    $client = Mockery::mock(XeroClientInterface::class);
    $client->shouldReceive('createInvoice')->once()->andReturn(['id' => 'invoice-race', 'status' => 'DRAFT']);
    $client->shouldReceive('updateInvoice')->once()->with('invoice-race', Mockery::any())->andReturn(['id' => 'invoice-race', 'status' => 'DRAFT']);
    app()->instance(XeroClientInterface::class, $client);
    app()->forgetInstance(XeroSyncService::class);

    $service = app(XeroSyncService::class);
    $service->syncOrderInvoice($order);
    $service->syncOrderInvoice($staleOrder);

    expect($order->fresh()->xero_invoice_id)->toBe('invoice-race');
});
