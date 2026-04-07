<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Jobs\SyncCustomerContactToXero;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Customer;
use Illuminate\Support\Facades\Queue;

it('queues a customer contact sync when a customer is created', function (): void {
    Queue::fake();

    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
    ]);

    Queue::assertPushed(SyncCustomerContactToXero::class, function (SyncCustomerContactToXero $job) use ($customer): bool {
        return $job->customerId === $customer->id;
    });
});

it('queues a customer contact sync when an unlinked customer is updated', function (): void {
    Queue::fake();

    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $customer->forceFill(['first_name' => 'Charlie'])->save();

    Queue::assertPushed(SyncCustomerContactToXero::class, function (SyncCustomerContactToXero $job) use ($customer): bool {
        return $job->customerId === $customer->id;
    });
});

it('does not queue a customer contact sync when the customer is already linked', function (): void {
    Queue::fake();

    $customer = Customer::query()->create([
        'email' => 'customer@example.com',
        'xero_contact_id' => 'contact-1',
    ]);

    Queue::clearResolvedInstances();
    Queue::fake();

    $customer->forceFill(['first_name' => 'Charlie'])->save();

    Queue::assertNotPushed(SyncCustomerContactToXero::class);
});
