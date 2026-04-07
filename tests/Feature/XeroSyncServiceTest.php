<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroSyncService;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Customer;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\OrderAddress;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\OrderLine;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Payment;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Product;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\ProductVariant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('syncs an order invoice and stores xero ids', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'first_name' => 'Charlie',
        'last_name' => 'Langridge',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Base Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'SKU-1',
        'option_values' => 'Large, Blue',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-1',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Order line',
        'quantity' => 2,
        'unit_price' => 19.99,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findContactByEmail')->once()->andReturn(null);
    $mock->shouldReceive('createContact')->once()->andReturn(['id' => 'contact-1', 'name' => 'Charlie Langridge', 'email' => 'customer@example.com']);
    $mock->shouldReceive('findOrCreateItem')->once()->with(Mockery::on(function (array $payload): bool {
        return $payload['item_code'] === 'SKU-1'
            && $payload['name'] === 'Base Product - Large, Blue'
            && $payload['description'] === 'Base Product - Large, Blue';
    }))->andReturn(['item_code' => 'SKU-1']);
    $mock->shouldReceive('createInvoice')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->reference === 'ORDER-1'
            && $payload->lines[0]->description === 'Base Product - Large, Blue'
            && $payload->lines[0]->taxType === 'ZERORATEDOUTPUT'
            && $payload->lines[0]->itemCode === 'SKU-1';
    }))->andReturn(['id' => 'invoice-1', 'number' => 'INV-1', 'status' => 'DRAFT']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $service = app(XeroSyncService::class);
    $service->syncOrderInvoice($order->fresh(['customer', 'lines.variant.product']));

    expect($order->fresh()->xero_invoice_id)->toBe('invoice-1')
        ->and($customer->fresh()->xero_contact_id)->toBe('contact-1')
        ->and($variant->fresh()->xero_item_code)->toBe('SKU-1');
});

it('falls back from variant to product to default account code', function (): void {
    $repository = app(XeroSettingsRepository::class);
    $repository->setDefaultAccountCode('999');

    $customer = Customer::query()->create(['email' => 'customer@example.com']);
    $product = Product::query()->create(['attribute_data' => ['name' => 'Base Product'], 'xero_account_code' => '200']);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'sku' => 'SKU-2']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-2']);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Order line',
        'quantity' => 1,
        'unit_price' => 10,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findContactByEmail')->andReturn(['id' => 'contact-2', 'name' => 'Example', 'email' => 'customer@example.com']);
    $mock->shouldReceive('findOrCreateItem')->andReturn(['item_code' => 'SKU-2']);
    $mock->shouldReceive('createInvoice')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->lines[0]->accountCode === '200';
    }))->andReturn(['id' => 'invoice-2']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['customer', 'lines.variant.product']));

    $variant->forceFill(['xero_account_code' => '201'])->save();

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findContactByEmail')->andReturn(['id' => 'contact-2', 'name' => 'Example', 'email' => 'customer@example.com']);
    $mock->shouldReceive('findOrCreateItem')->andReturn(['item_code' => 'SKU-2']);
    $mock->shouldReceive('createInvoice')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->lines[0]->accountCode === '201';
    }))->andReturn(['id' => 'invoice-3']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $secondOrder = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-3']);
    OrderLine::query()->create([
        'order_id' => $secondOrder->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Order line',
        'quantity' => 1,
        'unit_price' => 10,
    ]);

    app(XeroSyncService::class)->syncOrderInvoice($secondOrder->fresh(['customer', 'lines.variant.product']));

    expect($secondOrder->fresh()->xero_invoice_id)->toBe('invoice-3');
});

it('syncs payments against an existing invoice with configured mappings', function (): void {
    Queue::fake();

    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-1']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-4', 'xero_invoice_id' => 'invoice-4']);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'card',
        'amount' => 50,
        'reference' => 'PAY-1',
        'captured_at' => now(),
    ]);

    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getInvoicePayments')->once()->with('invoice-4')->andReturn([]);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === 'invoice-4'
            && $payload->creditNoteId === null
            && $payload->accountCode === '090'
            && $payload->reference === 'PAY-1';
    }))->andReturn(['id' => 'payment-1']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPayment($payment->fresh('order'));

    expect($result['id'])->toBe('payment-1');
});

it('prefers the payment driver for xero mappings when syncing stripe captures', function (): void {
    Queue::fake();

    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-stripe']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-STRIPE', 'xero_invoice_id' => 'invoice-stripe']);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'capture',
        'driver' => 'stripe',
        'amount' => 50,
        'reference' => 'ch_123',
        'captured_at' => now(),
    ]);

    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'stripe', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getInvoicePayments')->once()->with('invoice-stripe')->andReturn([]);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === 'invoice-stripe'
            && $payload->creditNoteId === null
            && $payload->accountCode === '090'
            && $payload->reference === 'ch_123';
    }))->andReturn(['id' => 'payment-stripe']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPayment($payment->fresh('order'));

    expect($result['id'])->toBe('payment-stripe');
});

it('falls back to a card payment mapping for stripe captures when no stripe mapping exists', function (): void {
    Queue::fake();

    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-card']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-CARD', 'xero_invoice_id' => 'invoice-card']);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'capture',
        'driver' => 'stripe',
        'amount' => 50,
        'reference' => 'ch_card',
        'captured_at' => now(),
    ]);

    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Card Clearing'],
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getInvoicePayments')->once()->with('invoice-card')->andReturn([]);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === 'invoice-card'
            && $payload->creditNoteId === null
            && $payload->accountCode === '090'
            && $payload->reference === 'ch_card';
    }))->andReturn(['id' => 'payment-card']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPayment($payment->fresh('order'));

    expect($result['id'])->toBe('payment-card');
});

it('creates a guest contact in xero from billing details when syncing an invoice', function (): void {
    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Guest Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'GUEST-1',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'reference' => 'ORDER-GUEST-1',
    ]);

    OrderAddress::query()->create([
        'order_id' => $order->id,
        'type' => 'billing',
        'first_name' => 'Guest',
        'last_name' => 'Buyer',
        'company_name' => 'Guest Co',
        'line_one' => '123 Example Street',
        'line_two' => 'Suite 5',
        'city' => 'Brighton',
        'state' => 'East Sussex',
        'postcode' => 'BN1 1AA',
        'country' => 'United Kingdom',
        'contact_email' => 'guest@example.com',
        'contact_phone' => '01234 567890',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Guest order line',
        'quantity' => 1,
        'unit_price' => 19.99,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findContactByEmail')->once()->with('guest@example.com')->andReturn(null);
    $mock->shouldReceive('createContact')->once()->with(Mockery::on(function (array $payload): bool {
        return $payload['email'] === 'guest@example.com'
            && $payload['name'] === 'Guest Co'
            && $payload['first_name'] === 'Guest'
            && $payload['last_name'] === 'Buyer'
            && $payload['company_name'] === 'Guest Co'
            && $payload['phone'] === '01234 567890'
            && $payload['address']['line_1'] === '123 Example Street'
            && $payload['address']['line_2'] === 'Suite 5'
            && $payload['address']['city'] === 'Brighton'
            && $payload['address']['region'] === 'East Sussex'
            && $payload['address']['postal_code'] === 'BN1 1AA'
            && $payload['address']['country'] === 'United Kingdom';
    }))->andReturn(['id' => 'contact-guest', 'name' => 'Guest Buyer', 'email' => 'guest@example.com']);
    $mock->shouldReceive('findOrCreateItem')->once()->andReturn(['item_code' => 'GUEST-1']);
    $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'invoice-guest']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['billingAddress', 'lines.variant.product']));

    expect($order->fresh()->xero_invoice_id)->toBe('invoice-guest');
});

it('prefers billing details when creating a guest-like xero contact from an order-linked customer record', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Guest Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'GUEST-LINKED-1',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-GUEST-LINKED-1',
    ]);

    OrderAddress::query()->create([
        'order_id' => $order->id,
        'type' => 'billing',
        'first_name' => 'Billing',
        'last_name' => 'Person',
        'company_name' => 'Billing Co',
        'line_one' => '1 Billing Road',
        'line_two' => 'Unit 2',
        'city' => 'Brighton',
        'state' => 'East Sussex',
        'postcode' => 'BN1 2AA',
        'country' => 'United Kingdom',
        'contact_email' => 'billing@example.com',
        'contact_phone' => '01234 111222',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Guest order line',
        'quantity' => 1,
        'unit_price' => 19.99,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findContactByEmail')->once()->with('billing@example.com')->andReturn(null);
    $mock->shouldReceive('createContact')->once()->with(Mockery::on(function (array $payload): bool {
        return $payload['email'] === 'billing@example.com'
            && $payload['name'] === 'Billing Co'
            && $payload['first_name'] === 'Billing'
            && $payload['last_name'] === 'Person'
            && $payload['company_name'] === 'Billing Co'
            && $payload['phone'] === '01234 111222'
            && $payload['address']['line_1'] === '1 Billing Road'
            && $payload['address']['line_2'] === 'Unit 2'
            && $payload['address']['city'] === 'Brighton'
            && $payload['address']['region'] === 'East Sussex'
            && $payload['address']['postal_code'] === 'BN1 2AA'
            && $payload['address']['country'] === 'United Kingdom';
    }))->andReturn(['id' => 'contact-guest-linked', 'name' => 'Billing Person', 'email' => 'billing@example.com']);
    $mock->shouldReceive('findOrCreateItem')->once()->andReturn(['item_code' => 'GUEST-LINKED-1']);
    $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'invoice-guest-linked']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['customer', 'billingAddress', 'lines.variant.product']));

    expect($customer->fresh()->xero_contact_id)->toBe('contact-guest-linked')
        ->and($order->fresh()->xero_invoice_id)->toBe('invoice-guest-linked');
});

it('backfills captured payments when an invoice is synced for an already paid order', function (): void {
    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-5']);
    $product = Product::query()->create(['xero_account_code' => '200', 'attribute_data' => ['name' => 'Paid Product']]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'sku' => 'PAID-1', 'xero_account_code' => '201']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-5']);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Paid line',
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'card',
        'amount' => 50,
        'reference' => 'PAY-ORDER-5',
        'captured_at' => now(),
    ]);

    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findOrCreateItem')->once()->andReturn(['item_code' => 'PAID-1']);
    $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'invoice-5']);
    $mock->shouldReceive('getInvoicePayments')->once()->with('invoice-5')->andReturn([]);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === 'invoice-5'
            && $payload->creditNoteId === null
            && $payload->accountCode === '090'
            && $payload->reference === 'PAY-ORDER-5';
    }))->andReturn(['id' => 'payment-5']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['customer', 'lines.variant.product', 'transactions']));

    expect($result['payments'][0]['status'])->toBe('synced')
        ->and($result['payments'][0]['result']['id'])->toBe('payment-5')
        ->and($order->fresh()->xero_invoice_id)->toBe('invoice-5');
});

it('backfills refund credit notes when an invoice is synced for an already refunded order', function (): void {
    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-5b']);
    $product = Product::query()->create(['xero_account_code' => '200', 'attribute_data' => ['name' => 'Refund Product']]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'sku' => 'REFUND-BACKFILL-1', 'xero_account_code' => '201']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-5B']);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Refund line',
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'refund',
        'driver' => 'stripe',
        'amount' => -20,
        'reference' => 're_backfill',
        'success' => true,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findOrCreateItem')->twice()->andReturn(['item_code' => 'REFUND-BACKFILL-1']);
    $mock->shouldReceive('createInvoice')->once()->andReturn(['id' => 'invoice-5b']);
    $mock->shouldReceive('findCreditNoteByReference')->once()->with('Refund re_backfill')->andReturn(null);
    $mock->shouldReceive('createCreditNote')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->reference === 'Refund re_backfill'
            && $payload->lines[0]->itemCode === 'REFUND-BACKFILL-1'
            && $payload->lines[0]->unitAmount === 20.0;
    }))->andReturn(['id' => 'credit-note-5b', 'number' => 'CN-5B', 'status' => 'AUTHORISED']);
    $mock->shouldReceive('allocateCreditNote')->once()->with('credit-note-5b', Mockery::on(function ($payload): bool {
        return $payload->invoiceId === 'invoice-5b'
            && $payload->amount === 20.0;
    }))->andReturn(['id' => 'allocation-5b']);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === null
            && $payload->creditNoteId === 'credit-note-5b'
            && $payload->accountCode === '090'
            && $payload->amount === 20.0
            && $payload->reference === 'Refund re_backfill';
    }))->andReturn(['id' => 'payment-credit-note-5b']);
    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['customer', 'lines.variant.product', 'transactions']));

    expect($result['payments'][0]['status'])->toBe('synced')
        ->and($result['payments'][0]['result']['credit_note']['id'])->toBe('credit-note-5b')
        ->and($result['payments'][0]['result']['payment']['id'])->toBe('payment-credit-note-5b')
        ->and($order->fresh()->xero_invoice_id)->toBe('invoice-5b');
});

it('does not update an existing xero invoice once payments or refunds exist and still backfills refunds', function (): void {
    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-locked']);
    $product = Product::query()->create(['xero_account_code' => '200', 'attribute_data' => ['name' => 'Locked Product']]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'sku' => 'LOCKED-1', 'xero_account_code' => '201']);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-LOCKED-1',
        'xero_invoice_id' => 'invoice-locked-1',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Locked line',
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'card',
        'amount' => 50,
        'reference' => 'PAY-LOCKED-1',
        'captured_at' => now(),
        'success' => true,
    ]);

    Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'refund',
        'driver' => 'stripe',
        'amount' => -20,
        'reference' => 're_locked',
        'success' => true,
    ]);

    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldNotReceive('updateInvoice');
    $mock->shouldReceive('getInvoicePayments')->once()->with('invoice-locked-1')->andReturn([]);
    $mock->shouldReceive('createPayment')->once()->andReturn(['id' => 'payment-locked-1']);
    $mock->shouldReceive('findOrCreateItem')->twice()->andReturn(['item_code' => 'LOCKED-1']);
    $mock->shouldReceive('findCreditNoteByReference')->once()->with('Refund re_locked')->andReturn(null);
    $mock->shouldReceive('createCreditNote')->once()->andReturn(['id' => 'credit-note-locked-1', 'number' => 'CN-LOCKED-1', 'status' => 'AUTHORISED']);
    $mock->shouldReceive('allocateCreditNote')->once()->andReturn(['id' => 'allocation-locked-1']);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === null
            && $payload->creditNoteId === 'credit-note-locked-1'
            && $payload->accountCode === '090'
            && $payload->amount === 20.0
            && $payload->reference === 'Refund re_locked';
    }))->andReturn(['id' => 'payment-credit-note-locked-1']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['customer', 'lines.variant.product', 'transactions']));

    expect($result['id'])->toBe('invoice-locked-1')
        ->and($result['status'])->toBe('skipped_existing_locked_invoice')
        ->and($result['payments'][0]['result']['id'])->toBe('payment-locked-1')
        ->and($result['payments'][1]['result']['credit_note']['id'])->toBe('credit-note-locked-1')
        ->and($result['payments'][1]['result']['payment']['id'])->toBe('payment-credit-note-locked-1');
});

it('allocates an existing xero credit note by reference instead of creating a duplicate', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'xero_contact_id' => 'contact-existing-refund',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Refunded Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'REFUND-EXISTING-1',
        'option_values' => 'Large',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-REFUND-EXISTING-1',
        'xero_invoice_id' => 'invoice-refund-existing-1',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Refunded line',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $refund = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'refund',
        'driver' => 'stripe',
        'amount' => -60,
        'reference' => 're_existing',
        'success' => true,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findOrCreateItem')->once()->andReturn(['item_code' => 'REFUND-EXISTING-1']);
    $mock->shouldReceive('findCreditNoteByReference')->once()->with('Refund re_existing')->andReturn([
        'id' => 'credit-note-existing-1',
        'number' => 'CN-EXISTING-1',
        'status' => 'AUTHORISED',
        'allocations' => [],
        'payments' => [],
    ]);
    $mock->shouldNotReceive('createCreditNote');
    $mock->shouldReceive('allocateCreditNote')->once()->with('credit-note-existing-1', Mockery::on(function ($payload): bool {
        return $payload->invoiceId === 'invoice-refund-existing-1'
            && $payload->amount === 60.0;
    }))->andReturn(['id' => 'allocation-existing-1']);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === null
            && $payload->creditNoteId === 'credit-note-existing-1'
            && $payload->accountCode === '090'
            && $payload->amount === 60.0
            && $payload->reference === 'Refund re_existing';
    }))->andReturn(['id' => 'payment-existing-1']);
    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPaymentById($refund->id, Payment::class);

    expect($result['credit_note']['id'])->toBe('credit-note-existing-1')
        ->and($result['allocation']['id'])->toBe('allocation-existing-1')
        ->and($result['payment']['id'])->toBe('payment-existing-1');
});

it('creates the cash-out payment when the credit note is already allocated but not yet paid out', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'xero_contact_id' => 'contact-allocated-refund',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Refunded Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'REFUND-ALLOCATED-1',
        'option_values' => 'Large',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-REFUND-ALLOCATED-1',
        'xero_invoice_id' => 'invoice-refund-allocated-1',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Refunded line',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $refund = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'refund',
        'driver' => 'stripe',
        'amount' => -60,
        'reference' => 're_allocated',
        'success' => true,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findOrCreateItem')->once()->andReturn(['item_code' => 'REFUND-ALLOCATED-1']);
    $mock->shouldReceive('findCreditNoteByReference')->once()->with('Refund re_allocated')->andReturn([
        'id' => 'credit-note-allocated-1',
        'number' => 'CN-ALLOCATED-1',
        'status' => 'AUTHORISED',
        'allocations' => [
            ['invoice_id' => 'invoice-refund-allocated-1', 'amount' => 60.0],
        ],
        'payments' => [],
    ]);
    $mock->shouldNotReceive('createCreditNote');
    $mock->shouldNotReceive('allocateCreditNote');
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === null
            && $payload->creditNoteId === 'credit-note-allocated-1'
            && $payload->accountCode === '090'
            && $payload->amount === 60.0
            && $payload->reference === 'Refund re_allocated';
    }))->andReturn(['id' => 'payment-allocated-1']);
    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPaymentById($refund->id, Payment::class);

    expect($result['credit_note']['id'])->toBe('credit-note-allocated-1')
        ->and($result['allocation']['id'])->toBe('already-allocated')
        ->and($result['payment']['id'])->toBe('payment-allocated-1');
});

it('does not let a previous successful refund log block payout completion on a later retry', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'xero_contact_id' => 'contact-retry-refund',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Refunded Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'REFUND-RETRY-1',
        'option_values' => 'Large',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-REFUND-RETRY-1',
        'xero_invoice_id' => 'invoice-refund-retry-1',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Refunded line',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $refund = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'refund',
        'driver' => 'stripe',
        'amount' => -60,
        'reference' => 're_retry',
        'success' => true,
    ]);

    XeroSyncLog::query()->create([
        'operation' => SyncOperation::CreditNote->value,
        'status' => SyncStatus::Succeeded->value,
        'resource_type' => Payment::class,
        'resource_id' => $refund->id,
        'external_reference' => Payment::class.':'.$refund->id,
        'payload' => ['refund_id' => $refund->id],
        'response' => ['credit_note' => ['id' => 'credit-note-retry-1']],
        'attempt' => 1,
        'started_at' => now()->subMinute(),
        'completed_at' => now()->subMinute(),
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findOrCreateItem')->once()->andReturn(['item_code' => 'REFUND-RETRY-1']);
    $mock->shouldReceive('findCreditNoteByReference')->once()->with('Refund re_retry')->andReturn([
        'id' => 'credit-note-retry-1',
        'number' => 'CN-RETRY-1',
        'status' => 'AUTHORISED',
        'allocations' => [
            ['invoice_id' => 'invoice-refund-retry-1', 'amount' => 60.0],
        ],
        'payments' => [],
    ]);
    $mock->shouldNotReceive('createCreditNote');
    $mock->shouldNotReceive('allocateCreditNote');
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === null
            && $payload->creditNoteId === 'credit-note-retry-1'
            && $payload->accountCode === '090'
            && $payload->amount === 60.0
            && $payload->reference === 'Refund re_retry';
    }))->andReturn(['id' => 'payment-retry-1']);
    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPaymentById($refund->id, Payment::class);

    expect($result['credit_note']['id'])->toBe('credit-note-retry-1')
        ->and($result['allocation']['id'])->toBe('already-allocated')
        ->and($result['payment']['id'])->toBe('payment-retry-1');
});

it('skips creating a xero payment when a matching payment already exists on the invoice', function (): void {
    Queue::fake();

    $customer = Customer::query()->create(['email' => 'customer@example.com', 'xero_contact_id' => 'contact-6']);
    $order = Order::query()->create(['customer_id' => $customer->id, 'reference' => 'ORDER-6', 'xero_invoice_id' => 'invoice-6']);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'card',
        'amount' => 50,
        'reference' => 'PAY-6',
        'captured_at' => now(),
    ]);

    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('getInvoicePayments')->once()->with('invoice-6')->andReturn([
        ['id' => 'payment-existing', 'reference' => 'PAY-6', 'amount' => 50.0, 'date' => now()->format('Y-m-d')],
    ]);
    $mock->shouldNotReceive('createPayment');
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPayment($payment->fresh('order'));

    expect($result['reason'])->toBe('payment_already_exists_in_xero')
        ->and($result['id'])->toBe('payment-existing');
});

it('creates and allocates a xero credit note for refund transactions', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'xero_contact_id' => 'contact-refund',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Refunded Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'REFUND-1',
        'option_values' => 'Large',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-REFUND-1',
        'xero_invoice_id' => 'invoice-refund-1',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Refunded line',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $refund = Payment::query()->create([
        'order_id' => $order->id,
        'type' => 'refund',
        'driver' => 'stripe',
        'amount' => -60,
        'reference' => 're_123',
        'success' => true,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findCreditNoteByReference')->once()->with('Refund re_123')->andReturn(null);
    $mock->shouldReceive('findOrCreateItem')->once()->with(Mockery::on(function (array $payload): bool {
        return $payload['item_code'] === 'REFUND-1'
            && $payload['name'] === 'Refunded Product - Large'
            && $payload['description'] === 'Refunded Product - Large';
    }))->andReturn(['item_code' => 'REFUND-1']);
    $mock->shouldReceive('createCreditNote')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->contactId === 'contact-refund'
            && $payload->reference === 'Refund re_123'
            && count($payload->lines) === 1
            && $payload->lines[0]->description === 'Refunded Product - Large'
            && $payload->lines[0]->unitAmount === 60.0
            && $payload->lines[0]->accountCode === '201'
            && $payload->lines[0]->itemCode === 'REFUND-1'
            && $payload->lines[0]->taxType === 'ZERORATEDOUTPUT';
    }))->andReturn(['id' => 'credit-note-1', 'number' => 'CN-1', 'status' => 'AUTHORISED']);
    $mock->shouldReceive('allocateCreditNote')->once()->with('credit-note-1', Mockery::on(function ($payload): bool {
        return $payload->invoiceId === 'invoice-refund-1'
            && $payload->amount === 60.0;
    }))->andReturn(['id' => 'allocation-1']);
    $mock->shouldReceive('createPayment')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->invoiceId === null
            && $payload->creditNoteId === 'credit-note-1'
            && $payload->accountCode === '090'
            && $payload->amount === 60.0
            && $payload->reference === 'Refund re_123';
    }))->andReturn(['id' => 'payment-refund-1']);
    app(XeroSettingsRepository::class)->syncPaymentMappings([
        ['payment_type' => 'card', 'account_code' => '090', 'account_name' => 'Stripe Clearing'],
    ]);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncPaymentById($refund->id, Payment::class);

    expect($result['credit_note']['id'])->toBe('credit-note-1')
        ->and($result['allocation']['id'])->toBe('allocation-1')
        ->and($result['payment']['id'])->toBe('payment-refund-1');
});

it('uses the customer reference for the xero invoice ref and updates an existing invoice when it changes', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'xero_contact_id' => 'contact-7',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Account Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'ACCOUNT-1',
        'option_values' => 'Net 30',
        'xero_account_code' => '201',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-7',
        'customer_reference' => 'PO-1234',
        'xero_invoice_id' => 'invoice-7',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Account line',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findOrCreateItem')->once()->andReturn(['item_code' => 'ACCOUNT-1']);
    $mock->shouldReceive('updateInvoice')->once()->with('invoice-7', Mockery::on(function ($payload): bool {
        return $payload->reference === 'PO-1234'
            && count($payload->lines) === 2
            && $payload->lines[0]->description === 'Account Product - Net 30'
            && $payload->lines[1]->description === 'Purchase Order: PO-1234'
            && $payload->lines[1]->unitAmount === 0.0;
    }))->andReturn(['id' => 'invoice-7', 'number' => 'INV-7', 'status' => 'AUTHORISED']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    $result = app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['customer', 'lines.variant.product']));

    expect($result['id'])->toBe('invoice-7')
        ->and($order->fresh()->xero_invoice_id)->toBe('invoice-7');
});

it('uses the variant sku as the invoice line xero item code even when a legacy item code is stored', function (): void {
    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'xero_contact_id' => 'contact-8',
    ]);

    $product = Product::query()->create([
        'xero_account_code' => '200',
        'attribute_data' => ['name' => 'Legacy Code Product'],
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'SKU-LEGACY-1',
        'option_values' => 'Single',
        'xero_account_code' => '201',
        'xero_item_code' => 'OLD-CODE',
    ]);

    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'reference' => 'ORDER-8',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'description' => 'Legacy line',
        'quantity' => 1,
        'unit_price' => 42,
    ]);

    $mock = Mockery::mock(XeroClientInterface::class);
    $mock->shouldReceive('findOrCreateItem')->once()->with(Mockery::on(function (array $payload): bool {
        return $payload['item_code'] === 'SKU-LEGACY-1'
            && $payload['name'] === 'Legacy Code Product - Single'
            && $payload['description'] === 'Legacy Code Product - Single';
    }))->andReturn(['item_code' => 'SKU-LEGACY-1']);
    $mock->shouldReceive('createInvoice')->once()->with(Mockery::on(function ($payload): bool {
        return $payload->lines[0]->itemCode === 'SKU-LEGACY-1'
            && $payload->lines[0]->description === 'Legacy Code Product - Single';
    }))->andReturn(['id' => 'invoice-8']);
    app()->instance(XeroClientInterface::class, $mock);
    app()->forgetInstance(XeroSyncService::class);

    app(XeroSyncService::class)->syncOrderInvoice($order->fresh(['customer', 'lines.variant.product']));

    expect($variant->fresh()->xero_item_code)->toBe('SKU-LEGACY-1')
        ->and($order->fresh()->xero_invoice_id)->toBe('invoice-8');
});
