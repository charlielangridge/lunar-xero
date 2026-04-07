<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Exceptions\XeroConfigurationException;
use CharlieLangridge\LunarXero\Exceptions\XeroSyncException;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroSyncService;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Customer;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Payment;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('skips customer contact sync when no email can be resolved', function (): void {
    $customer = Customer::query()->create();

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldNotReceive('findContactByEmail');
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncCustomerContact($customer);

    expect($result['reason'])->toBe('no_customer_email')
        ->and(XeroSyncLog::query()->where('operation', SyncOperation::Contact->value)->latest('id')->first()?->status)
        ->toBe(SyncStatus::Skipped);
});

it('returns already-synced when the payment was previously synced successfully', function (): void {
    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-1']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'xero_invoice_id' => 'invoice-1']);
    $payment = Payment::query()->create(['order_id' => $order->id]);

    XeroSyncLog::query()->create([
        'operation' => SyncOperation::Payment->value,
        'status' => SyncStatus::Succeeded->value,
        'resource_type' => $payment::class,
        'resource_id' => $payment->id,
        'external_reference' => sprintf('%s:%s', $payment::class, $payment->id),
        'payload' => ['payment_id' => $payment->id],
        'attempt' => 1,
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldNotReceive('getInvoicePayments');
    $mock->shouldNotReceive('createPayment');
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    expect(app(XeroSyncService::class)->syncPayment($payment->fresh('order')))
        ->toBe(['id' => 'already-synced']);
});

it('fails payment sync when no xero payment mapping exists', function (): void {
    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-1']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'xero_invoice_id' => 'invoice-2']);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'card',
        'amount' => 20,
        'captured_at' => now(),
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    expect(fn () => app(XeroSyncService::class)->syncPayment($payment->fresh('order')))
        ->toThrow(XeroConfigurationException::class, 'No Xero payment mapping exists for payment type [card].');
});

it('fails payment sync when the order has not been synced as an invoice yet', function (): void {
    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-1']);
    $order = Order::query()->create(['customer_id' => $customer->id]);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'card',
        'amount' => 20,
        'captured_at' => now(),
    ]);

    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    expect(fn () => app(XeroSyncService::class)->syncPayment($payment->fresh('order')))
        ->toThrow(XeroSyncException::class, 'The Lunar order does not have a synced Xero invoice ID.');
});

it('fails invoice sync when an order has no lines', function (): void {
    $customer = Customer::query()->create(['email' => 'customer@example.com']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-NO-LINES']);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findContactByEmail')->once()->andReturn(['id' => 'contact-1', 'email' => 'customer@example.com']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    expect(fn () => app(XeroSyncService::class)->syncOrderInvoice($order->fresh('customer')))
        ->toThrow(XeroSyncException::class, 'Cannot create a Xero invoice for an order without lines.');

    $log = XeroSyncLog::query()
        ->where('operation', SyncOperation::Invoice->value)
        ->latest('id')
        ->first();

    expect($log?->status)->toBe(SyncStatus::Failed)
        ->and($log?->error_message)->toBe('Cannot create a Xero invoice for an order without lines.');
});
