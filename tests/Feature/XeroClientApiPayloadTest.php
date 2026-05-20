<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use CharlieLangridge\LunarXero\Data\CreditNoteAllocationPayload;
use CharlieLangridge\LunarXero\Data\CreditNotePayload;
use CharlieLangridge\LunarXero\Data\InvoiceLineData;
use CharlieLangridge\LunarXero\Data\InvoicePayload;
use CharlieLangridge\LunarXero\Data\PaymentPayload;
use CharlieLangridge\LunarXero\Exceptions\XeroTransportException;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroClient;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\ApiException;
use XeroAPI\XeroPHP\Models\Accounting\Allocation;
use XeroAPI\XeroPHP\Models\Accounting\Allocations;
use XeroAPI\XeroPHP\Models\Accounting\Bill;
use XeroAPI\XeroPHP\Models\Accounting\Contact;
use XeroAPI\XeroPHP\Models\Accounting\ContactPerson;
use XeroAPI\XeroPHP\Models\Accounting\Contacts;
use XeroAPI\XeroPHP\Models\Accounting\CreditNote;
use XeroAPI\XeroPHP\Models\Accounting\CreditNotes;
use XeroAPI\XeroPHP\Models\Accounting\Invoice;
use XeroAPI\XeroPHP\Models\Accounting\Invoices;
use XeroAPI\XeroPHP\Models\Accounting\Item;
use XeroAPI\XeroPHP\Models\Accounting\Items;
use XeroAPI\XeroPHP\Models\Accounting\OnlineInvoice;
use XeroAPI\XeroPHP\Models\Accounting\OnlineInvoices;
use XeroAPI\XeroPHP\Models\Accounting\Payment;
use XeroAPI\XeroPHP\Models\Accounting\Payments;
use XeroAPI\XeroPHP\Models\Accounting\PaymentTerm;
use XeroAPI\XeroPHP\Models\Accounting\PaymentTermType;
use XeroAPI\XeroPHP\Models\Accounting\RequestEmpty;

beforeEach(function (): void {
    config()->set('lunarpanel-xero.oauth.read_only', false);
});

function fakeXeroClient(AccountingApi $api, ?Contact $contact = null): XeroClient
{
    return new class(app(XeroSettingsRepository::class), $api, $contact) extends XeroClient
    {
        public function __construct(
            XeroSettingsRepository $settingsRepository,
            private readonly AccountingApi $api,
            private readonly ?Contact $contact = null,
        ) {
            parent::__construct($settingsRepository);
        }

        protected function accountingApi(): AccountingApi
        {
            return $this->api;
        }

        protected function tenantId(): string
        {
            return 'tenant-123';
        }

        protected function fetchContact(string $contactId): ?Contact
        {
            return $this->contact;
        }
    };
}

it('creates contacts using the xero bulk contacts payload shape', function (): void {
    $createdContact = new Contact;
    $createdContact->setContactID('contact-123');
    $createdContact->setName('Charlie Langridge');
    $createdContact->setEmailAddress('charlie@example.com');

    $response = new Contacts;
    $response->setContacts([$createdContact]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('createContacts')
        ->once()
        ->withArgs(function (string $tenantId, Contacts $contacts): bool {
            $contact = $contacts->getContacts()[0];
            $address = $contact->getAddresses()[0];
            $phone = $contact->getPhones()[0];

            return $tenantId === 'tenant-123'
                && $contact->getName() === 'Charlie Langridge'
                && $contact->getFirstName() === 'Charlie'
                && $contact->getLastName() === 'Langridge'
                && $contact->getEmailAddress() === 'charlie@example.com'
                && $address->getAddressLine1() === '1 Example Road'
                && $address->getAddressLine2() === 'Suite 2'
                && $address->getCity() === 'Brighton'
                && $address->getRegion() === 'East Sussex'
                && $address->getPostalCode() === 'BN1 1AA'
                && $address->getCountry() === 'United Kingdom'
                && $phone->getPhoneNumber() === '01234 567890';
        })
        ->andReturn($response);

    $result = fakeXeroClient($api)->createContact([
        'name' => 'Charlie Langridge',
        'first_name' => 'Charlie',
        'last_name' => 'Langridge',
        'email' => 'charlie@example.com',
        'phone' => '01234 567890',
        'address' => [
            'line_1' => '1 Example Road',
            'line_2' => 'Suite 2',
            'city' => 'Brighton',
            'region' => 'East Sussex',
            'postal_code' => 'BN1 1AA',
            'country' => 'United Kingdom',
        ],
    ]);

    expect($result)->toBe([
        'id' => 'contact-123',
        'name' => 'Charlie Langridge',
        'email' => 'charlie@example.com',
    ]);
});

it('wraps xero sdk errors when fetching accounts', function (): void {
    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('getAccounts')
        ->once()
        ->with('tenant-123', null, 'Status=="ACTIVE"', 'Code ASC')
        ->andThrow(new ApiException('Forbidden', 403, [], json_encode([
            'Message' => 'The tenant is not available for this connection.',
        ])));

    expect(fn () => fakeXeroClient($api)->getAccounts())
        ->toThrow(XeroTransportException::class, 'Unable to fetch accounts from Xero: The tenant is not available for this connection.');
});

it('creates invoices using the xero invoices payload and idempotency key parameter', function (): void {
    $createdInvoice = new Invoice;
    $createdInvoice->setInvoiceID('invoice-123');
    $createdInvoice->setInvoiceNumber('INV-123');
    $createdInvoice->setStatus('DRAFT');

    $response = new Invoices;
    $response->setInvoices([$createdInvoice]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('createInvoices')
        ->once()
        ->withArgs(function (
            string $tenantId,
            Invoices $invoices,
            bool $summarizeErrors,
            $unitdp,
            string $idempotencyKey,
        ): bool {
            $invoice = $invoices->getInvoices()[0];
            $lineItem = $invoice->getLineItems()[0];

            return $tenantId === 'tenant-123'
                && $summarizeErrors === false
                && $unitdp === null
                && $idempotencyKey !== ''
                && $invoice->getContact()->getContactID() === 'contact-123'
                && $invoice->getType() === 'ACCREC'
                && $invoice->getReference() === 'ORDER-123'
                && $invoice->getStatus() === 'DRAFT'
                && $lineItem->getDescription() === 'Example Product'
                && $lineItem->getQuantity() === 2.0
                && $lineItem->getUnitAmount() === 19.99
                && $lineItem->getAccountCode() === '200'
                && $lineItem->getTaxType() === 'OUTPUT2'
                && $lineItem->getItemCode() === 'SKU-123';
        })
        ->andReturn($response);

    $result = fakeXeroClient($api)->createInvoice(new InvoicePayload(
        contactId: 'contact-123',
        status: 'DRAFT',
        reference: 'ORDER-123',
        lines: [
            new InvoiceLineData(
                description: 'Example Product',
                quantity: 2,
                unitAmount: 19.99,
                accountCode: '200',
                itemCode: 'SKU-123',
                taxType: 'OUTPUT2',
            ),
        ],
    ));

    expect($result)->toBe([
        'id' => 'invoice-123',
        'number' => 'INV-123',
        'status' => 'DRAFT',
    ]);
});

it('creates payments using the xero payments payload and idempotency key parameter', function (): void {
    $createdPayment = new Payment;
    $createdPayment->setPaymentID('payment-123');

    $response = new Payments;
    $response->setPayments([$createdPayment]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('createPayments')
        ->once()
        ->withArgs(function (
            string $tenantId,
            Payments $payments,
            bool $summarizeErrors,
            string $idempotencyKey,
        ): bool {
            $payment = $payments->getPayments()[0];

            return $tenantId === 'tenant-123'
                && $summarizeErrors === false
                && $idempotencyKey !== ''
                && $payment->getInvoice()->getInvoiceID() === 'invoice-123'
                && $payment->getAccount()->getCode() === '090'
                && $payment->getAmount() === 50.0
                && $payment->getDate() === '2026-04-06'
                && $payment->getReference() === 'PAY-123';
        })
        ->andReturn($response);

    $result = fakeXeroClient($api)->createPayment(new PaymentPayload(
        invoiceId: 'invoice-123',
        creditNoteId: null,
        accountCode: '090',
        amount: 50.0,
        date: CarbonImmutable::parse('2026-04-06'),
        reference: 'PAY-123',
    ));

    expect($result)->toBe(['id' => 'payment-123']);
});

it('fetches an online invoice url from xero', function (): void {
    $onlineInvoice = new OnlineInvoice;
    $onlineInvoice->setOnlineInvoiceUrl('https://in.xero.com/invoice/invoice-123');

    $response = new OnlineInvoices;
    $response->setOnlineInvoices([$onlineInvoice]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('getOnlineInvoice')
        ->once()
        ->with('tenant-123', 'invoice-123')
        ->andReturn($response);

    expect(fakeXeroClient($api)->getOnlineInvoiceUrl('invoice-123'))->toBe('https://in.xero.com/invoice/invoice-123');
});

it('fetches invoice status and sent to contact state', function (): void {
    $invoice = new Invoice;
    $invoice->setInvoiceID('invoice-sent-123');
    $invoice->setInvoiceNumber('INV-SENT-123');
    $invoice->setStatus('AUTHORISED');
    $invoice->setSentToContact(true);

    $response = new Invoices;
    $response->setInvoices([$invoice]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('getInvoice')
        ->once()
        ->with('tenant-123', 'invoice-sent-123')
        ->andReturn($response);

    expect(fakeXeroClient($api)->getInvoice('invoice-sent-123'))->toBe([
        'id' => 'invoice-sent-123',
        'number' => 'INV-SENT-123',
        'status' => 'AUTHORISED',
        'sent_to_contact' => true,
    ]);
});

it('emails invoices using xero invoice email endpoint', function (): void {
    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('emailInvoice')
        ->once()
        ->withArgs(function (
            string $tenantId,
            string $invoiceId,
            RequestEmpty $request,
            string $idempotencyKey,
        ): bool {
            return $tenantId === 'tenant-123'
                && $invoiceId === 'invoice-email-123'
                && $idempotencyKey !== '';
        });

    fakeXeroClient($api)->emailInvoice('invoice-email-123');
});

it('adds the order email as a xero contact person before emailing when it is missing', function (): void {
    $contact = new Contact;
    $contact->setContactID('contact-recipient-1');
    $contact->setName('Recipient Co');
    $contact->setEmailAddress('accounts@example.com');
    $contact->setContactPersons([
        (new ContactPerson)
            ->setFirstName('Existing')
            ->setLastName('Recipient')
            ->setEmailAddress('existing@example.com')
            ->setIncludeInEmails(true),
    ]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('updateContact')
        ->once()
        ->withArgs(function (string $tenantId, string $contactId, Contacts $contacts): bool {
            $people = $contacts->getContacts()[0]->getContactPersons();

            return $tenantId === 'tenant-123'
                && $contactId === 'contact-recipient-1'
                && count($people) === 2
                && $people[0]->getEmailAddress() === 'existing@example.com'
                && $people[0]->getIncludeInEmails() === true
                && $people[1]->getEmailAddress() === 'order@example.com'
                && $people[1]->getIncludeInEmails() === true;
        });

    $result = fakeXeroClient($api, $contact)->prepareInvoiceEmailRecipients('contact-recipient-1', 'order@example.com');

    expect($result)->toBe([
        'recipient_count' => 3,
        'changed' => true,
        'order_email_added' => true,
        'duplicate_count' => 0,
    ]);
});

it('deduplicates xero contact person recipients before emailing', function (): void {
    $contact = new Contact;
    $contact->setContactID('contact-recipient-2');
    $contact->setName('Recipient Co');
    $contact->setEmailAddress('Order@example.com');
    $contact->setContactPersons([
        (new ContactPerson)
            ->setFirstName('Duplicate')
            ->setLastName('Primary')
            ->setEmailAddress('order@example.com')
            ->setIncludeInEmails(true),
        (new ContactPerson)
            ->setFirstName('Accounts')
            ->setLastName('Team')
            ->setEmailAddress('accounts@example.com')
            ->setIncludeInEmails(true),
        (new ContactPerson)
            ->setFirstName('Accounts')
            ->setLastName('Duplicate')
            ->setEmailAddress('ACCOUNTS@example.com')
            ->setIncludeInEmails(true),
    ]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('updateContact')
        ->once()
        ->withArgs(function (string $tenantId, string $contactId, Contacts $contacts): bool {
            $people = $contacts->getContacts()[0]->getContactPersons();

            return $tenantId === 'tenant-123'
                && $contactId === 'contact-recipient-2'
                && $people[0]->getEmailAddress() === 'order@example.com'
                && $people[0]->getIncludeInEmails() === false
                && $people[1]->getEmailAddress() === 'accounts@example.com'
                && $people[1]->getIncludeInEmails() === true
                && $people[2]->getEmailAddress() === 'ACCOUNTS@example.com'
                && $people[2]->getIncludeInEmails() === false;
        });

    $result = fakeXeroClient($api, $contact)->prepareInvoiceEmailRecipients('contact-recipient-2', ' order@example.com ');

    expect($result)->toBe([
        'recipient_count' => 2,
        'changed' => true,
        'order_email_added' => false,
        'duplicate_count' => 2,
    ]);
});

it('enables an existing matching xero contact person without adding another recipient', function (): void {
    $contact = new Contact;
    $contact->setContactID('contact-recipient-3');
    $contact->setName('Recipient Co');
    $contact->setEmailAddress('accounts@example.com');
    $contact->setContactPersons([
        (new ContactPerson)
            ->setFirstName('Order')
            ->setLastName('Recipient')
            ->setEmailAddress('order@example.com')
            ->setIncludeInEmails(false),
    ]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('updateContact')
        ->once()
        ->withArgs(function (string $tenantId, string $contactId, Contacts $contacts): bool {
            $people = $contacts->getContacts()[0]->getContactPersons();

            return $tenantId === 'tenant-123'
                && $contactId === 'contact-recipient-3'
                && count($people) === 1
                && $people[0]->getEmailAddress() === 'order@example.com'
                && $people[0]->getIncludeInEmails() === true;
        });

    $result = fakeXeroClient($api, $contact)->prepareInvoiceEmailRecipients('contact-recipient-3', 'order@example.com');

    expect($result)->toBe([
        'recipient_count' => 2,
        'changed' => true,
        'order_email_added' => false,
        'duplicate_count' => 0,
    ]);
});

it('does not update xero contact recipients when the order email is already included once', function (): void {
    $contact = new Contact;
    $contact->setContactID('contact-recipient-4');
    $contact->setName('Recipient Co');
    $contact->setEmailAddress('accounts@example.com');
    $contact->setContactPersons([
        (new ContactPerson)
            ->setFirstName('Order')
            ->setLastName('Recipient')
            ->setEmailAddress('order@example.com')
            ->setIncludeInEmails(true),
    ]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldNotReceive('updateContact');

    $result = fakeXeroClient($api, $contact)->prepareInvoiceEmailRecipients('contact-recipient-4', 'order@example.com');

    expect($result)->toBe([
        'recipient_count' => 2,
        'changed' => false,
        'order_email_added' => false,
        'duplicate_count' => 0,
    ]);
});

it('creates credit note payments using the xero payments payload and idempotency key parameter', function (): void {
    $createdPayment = new Payment;
    $createdPayment->setPaymentID('payment-credit-note-123');

    $response = new Payments;
    $response->setPayments([$createdPayment]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('createPayments')
        ->once()
        ->withArgs(function (
            string $tenantId,
            Payments $payments,
            bool $summarizeErrors,
            string $idempotencyKey,
        ): bool {
            $payment = $payments->getPayments()[0];

            return $tenantId === 'tenant-123'
                && $summarizeErrors === false
                && $idempotencyKey !== ''
                && $payment->getInvoice() === null
                && $payment->getCreditNote()->getCreditNoteId() === 'credit-note-123'
                && $payment->getAccount()->getCode() === '090'
                && $payment->getAmount() === 50.0
                && $payment->getDate() === '2026-04-06'
                && $payment->getReference() === 'REFUND-123';
        })
        ->andReturn($response);

    $result = fakeXeroClient($api)->createPayment(new PaymentPayload(
        invoiceId: null,
        creditNoteId: 'credit-note-123',
        accountCode: '090',
        amount: 50.0,
        date: CarbonImmutable::parse('2026-04-06'),
        reference: 'REFUND-123',
    ));

    expect($result)->toBe(['id' => 'payment-credit-note-123']);
});

it('creates credit notes using the xero credit note payload and idempotency key parameter', function (): void {
    $createdCreditNote = new CreditNote;
    $createdCreditNote->setCreditNoteId('credit-note-123');
    $createdCreditNote->setCreditNoteNumber('CN-123');
    $createdCreditNote->setStatus('AUTHORISED');

    $response = new CreditNotes;
    $response->setCreditNotes([$createdCreditNote]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('createCreditNotes')
        ->once()
        ->withArgs(function (
            string $tenantId,
            CreditNotes $creditNotes,
            bool $summarizeErrors,
            $unitdp,
            string $idempotencyKey,
        ): bool {
            $creditNote = $creditNotes->getCreditNotes()[0];
            $lineItem = $creditNote->getLineItems()[0];

            return $tenantId === 'tenant-123'
                && $summarizeErrors === false
                && $unitdp === null
                && $idempotencyKey !== ''
                && $creditNote->getContact()->getContactID() === 'contact-123'
                && $creditNote->getType() === 'ACCRECCREDIT'
                && $creditNote->getReference() === 'Refund ORDER-123'
                && $creditNote->getStatus() === 'AUTHORISED'
                && $creditNote->getDate() === '2026-04-07'
                && $lineItem->getDescription() === 'Example Product'
                && $lineItem->getQuantity() === 1.0
                && $lineItem->getUnitAmount() === 19.99
                && $lineItem->getAccountCode() === '200'
                && $lineItem->getTaxType() === 'OUTPUT2'
                && $lineItem->getItemCode() === 'SKU-123';
        })
        ->andReturn($response);

    $result = fakeXeroClient($api)->createCreditNote(new CreditNotePayload(
        contactId: 'contact-123',
        reference: 'Refund ORDER-123',
        lines: [
            new InvoiceLineData(
                description: 'Example Product',
                quantity: 1,
                unitAmount: 19.99,
                accountCode: '200',
                itemCode: 'SKU-123',
                taxType: 'OUTPUT2',
            ),
        ],
        date: CarbonImmutable::parse('2026-04-07'),
    ));

    expect($result)->toBe([
        'id' => 'credit-note-123',
        'number' => 'CN-123',
        'status' => 'AUTHORISED',
    ]);
});

it('allocates credit notes to invoices using the xero allocations payload', function (): void {
    $createdAllocation = new Allocation;
    $createdAllocation->setAllocationId('allocation-123');

    $response = new Allocations;
    $response->setAllocations([$createdAllocation]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('createCreditNoteAllocation')
        ->once()
        ->withArgs(function (
            string $tenantId,
            string $creditNoteId,
            Allocations $allocations,
            bool $summarizeErrors,
            string $idempotencyKey,
        ): bool {
            $allocation = $allocations->getAllocations()[0];

            return $tenantId === 'tenant-123'
                && $creditNoteId === 'credit-note-123'
                && $summarizeErrors === false
                && $idempotencyKey !== ''
                && $allocation->getInvoice()->getInvoiceID() === 'invoice-123'
                && $allocation->getAmount() === 19.99
                && $allocation->getDate() === '2026-04-07';
        })
        ->andReturn($response);

    $result = fakeXeroClient($api)->allocateCreditNote(
        'credit-note-123',
        new CreditNoteAllocationPayload(
            invoiceId: 'invoice-123',
            amount: 19.99,
            date: CarbonImmutable::parse('2026-04-07'),
        ),
    );

    expect($result)->toBe(['id' => 'allocation-123']);
});

it('finds an existing credit note by reference and exposes its allocations', function (): void {
    $invoice = new Invoice;
    $invoice->setInvoiceID('invoice-123');

    $allocation = new Allocation;
    $allocation->setInvoice($invoice);
    $allocation->setAmount(19.99);

    $creditNote = new CreditNote;
    $creditNote->setCreditNoteId('credit-note-123');
    $creditNote->setCreditNoteNumber('CN-123');
    $creditNote->setStatus('AUTHORISED');
    $creditNote->setReference('Refund ORDER-123');
    $creditNote->setAllocations([$allocation]);

    $response = new CreditNotes;
    $response->setCreditNotes([$creditNote]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('getCreditNotes')
        ->once()
        ->with('tenant-123')
        ->andReturn($response);

    $result = fakeXeroClient($api)->findCreditNoteByReference('Refund ORDER-123');

    expect($result)->toBe([
        'id' => 'credit-note-123',
        'number' => 'CN-123',
        'status' => 'AUTHORISED',
        'allocations' => [
            [
                'invoice_id' => 'invoice-123',
                'amount' => 19.99,
            ],
        ],
        'payments' => [],
    ]);
});

it('creates items using the xero items payload shape when an item code does not already exist', function (): void {
    $existingItems = new Items;
    $existingItems->setItems([]);

    $createdItem = new Item;
    $createdItem->setCode('SKU-123');

    $createdItems = new Items;
    $createdItems->setItems([$createdItem]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('getItems')
        ->once()
        ->with('tenant-123')
        ->andReturn($existingItems);
    $api->shouldReceive('createItems')
        ->once()
        ->withArgs(function (string $tenantId, Items $items): bool {
            $item = $items->getItems()[0];

            return $tenantId === 'tenant-123'
                && $item->getCode() === 'SKU-123'
                && $item->getName() === 'Example Product'
                && $item->getDescription() === 'A synced item';
        })
        ->andReturn($createdItems);

    $result = fakeXeroClient($api)->findOrCreateItem([
        'item_code' => 'SKU-123',
        'name' => 'Example Product',
        'description' => 'A synced item',
    ]);

    expect($result)->toBe(['item_code' => 'SKU-123']);
});

it('truncates xero item names to the api limit while keeping the full description', function (): void {
    $existingItems = new Items;
    $existingItems->setItems([]);

    $createdItem = new Item;
    $createdItem->setCode('SKU-LONG-123');

    $createdItems = new Items;
    $createdItems->setItems([$createdItem]);

    $longName = 'Folded Flyers - A6 - 120gsm Uncoated - 4pp - 50 - Half Fold';

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('getItems')
        ->once()
        ->with('tenant-123')
        ->andReturn($existingItems);
    $api->shouldReceive('createItems')
        ->once()
        ->withArgs(function (string $tenantId, Items $items) use ($longName): bool {
            $item = $items->getItems()[0];
            $name = $item->getName();

            return $tenantId === 'tenant-123'
                && $item->getCode() === 'SKU-LONG-123'
                && is_string($name)
                && mb_strlen($name) <= 50
                && $item->getDescription() === $longName;
        })
        ->andReturn($createdItems);

    $result = fakeXeroClient($api)->findOrCreateItem([
        'item_code' => 'SKU-LONG-123',
        'name' => $longName,
        'description' => $longName,
    ]);

    expect($result)->toBe(['item_code' => 'SKU-LONG-123']);
});

it('returns the existing xero item code without creating a duplicate item', function (): void {
    $existingItem = new Item;
    $existingItem->setCode('SKU-123');

    $existingItems = new Items;
    $existingItems->setItems([$existingItem]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('getItems')
        ->once()
        ->with('tenant-123')
        ->andReturn($existingItems);
    $api->shouldNotReceive('createItems');

    $result = fakeXeroClient($api)->findOrCreateItem([
        'item_code' => 'SKU-123',
        'name' => 'Example Product',
    ]);

    expect($result)->toBe(['item_code' => 'SKU-123']);
});

it('uses the contact sales payment terms when building invoice due dates for xero', function (): void {
    $contact = new Contact;

    $salesTerms = new Bill;
    $salesTerms->setType(PaymentTermType::DAYSAFTERBILLDATE);
    $salesTerms->setDay(14);

    $paymentTerms = new PaymentTerm;
    $paymentTerms->setSales($salesTerms);
    $contact->setPaymentTerms($paymentTerms);

    $createdInvoice = new Invoice;
    $createdInvoice->setInvoiceID('invoice-terms');

    $response = new Invoices;
    $response->setInvoices([$createdInvoice]);

    $api = Mockery::mock(AccountingApi::class);
    $api->shouldReceive('createInvoices')
        ->once()
        ->withArgs(function (
            string $tenantId,
            Invoices $invoices,
            bool $summarizeErrors,
            $unitdp,
            string $idempotencyKey,
        ): bool {
            $invoice = $invoices->getInvoices()[0];
            $issueDate = CarbonImmutable::instance($invoice->getDate());
            $dueDate = CarbonImmutable::instance($invoice->getDueDate());

            return $tenantId === 'tenant-123'
                && $summarizeErrors === false
                && $unitdp === null
                && $idempotencyKey !== ''
                && $dueDate->toDateString() === $issueDate->addDays(14)->toDateString();
        })
        ->andReturn($response);

    fakeXeroClient($api, $contact)->createInvoice(new InvoicePayload(
        contactId: 'contact-terms',
        status: 'DRAFT',
        reference: 'ORDER-TERMS',
        lines: [
            new InvoiceLineData(
                description: 'Terms Product',
                quantity: 1,
                unitAmount: 10.0,
                accountCode: '200',
            ),
        ],
    ));
});
