<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Services;

use Carbon\CarbonImmutable;
use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Data\AccountOption;
use CharlieLangridge\LunarXero\Data\CreditNoteAllocationPayload;
use CharlieLangridge\LunarXero\Data\CreditNotePayload;
use CharlieLangridge\LunarXero\Data\InvoicePayload;
use CharlieLangridge\LunarXero\Data\PaymentPayload;
use CharlieLangridge\LunarXero\Data\TenantData;
use CharlieLangridge\LunarXero\Exceptions\XeroAuthenticationException;
use CharlieLangridge\LunarXero\Exceptions\XeroConfigurationException;
use CharlieLangridge\LunarXero\Exceptions\XeroTransportException;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Throwable;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\ApiException;
use XeroAPI\XeroPHP\Configuration;
use XeroAPI\XeroPHP\Models\Accounting\Account;
use XeroAPI\XeroPHP\Models\Accounting\Address;
use XeroAPI\XeroPHP\Models\Accounting\Allocation;
use XeroAPI\XeroPHP\Models\Accounting\Allocations;
use XeroAPI\XeroPHP\Models\Accounting\Contact;
use XeroAPI\XeroPHP\Models\Accounting\ContactPerson;
use XeroAPI\XeroPHP\Models\Accounting\Contacts;
use XeroAPI\XeroPHP\Models\Accounting\CreditNote;
use XeroAPI\XeroPHP\Models\Accounting\CreditNotes;
use XeroAPI\XeroPHP\Models\Accounting\Invoice;
use XeroAPI\XeroPHP\Models\Accounting\Invoices;
use XeroAPI\XeroPHP\Models\Accounting\Item;
use XeroAPI\XeroPHP\Models\Accounting\Items;
use XeroAPI\XeroPHP\Models\Accounting\LineItem;
use XeroAPI\XeroPHP\Models\Accounting\OnlineInvoice;
use XeroAPI\XeroPHP\Models\Accounting\Payment;
use XeroAPI\XeroPHP\Models\Accounting\Payments;
use XeroAPI\XeroPHP\Models\Accounting\PaymentTermType;
use XeroAPI\XeroPHP\Models\Accounting\Phone;
use XeroAPI\XeroPHP\Models\Accounting\RequestEmpty;

class XeroClient implements XeroClientInterface
{
    public function __construct(
        protected XeroSettingsRepository $settingsRepository,
    ) {}

    public function getAuthorizationUrl(): string
    {
        $this->guardOAuthConfiguration();

        $state = Str::random(40);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        Session::put('lunarpanel_xero.oauth_state', $state);
        Session::put('lunarpanel_xero.oauth_verifier', $verifier);

        $query = Arr::query([
            'response_type' => 'code',
            'client_id' => config('lunarpanel-xero.oauth.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', $this->authorizationScopes()),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return rtrim((string) config('lunarpanel-xero.oauth.authorize_url'), '?').'?'.$query;
    }

    public function handleCallback(string $code, string $state): void
    {
        $expectedState = Session::pull('lunarpanel_xero.oauth_state');
        $verifier = Session::pull('lunarpanel_xero.oauth_verifier');

        if (! $expectedState || ! hash_equals((string) $expectedState, $state)) {
            throw new XeroAuthenticationException('The returned Xero OAuth state did not match the active session.');
        }

        if (! $verifier) {
            throw new XeroAuthenticationException('The PKCE verifier is missing from the session.');
        }

        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);

        if ($response->failed()) {
            throw new XeroAuthenticationException('Failed to exchange the authorisation code with Xero.');
        }

        $payload = $response->json();

        $this->settingsRepository->storeToken([
            'access_token' => $payload['access_token'] ?? null,
            'refresh_token' => $payload['refresh_token'] ?? null,
            'id_token' => $payload['id_token'] ?? null,
            'token_type' => $payload['token_type'] ?? 'Bearer',
            'expires_at' => isset($payload['expires_in']) ? now()->addSeconds((int) $payload['expires_in']) : null,
            'scopes' => isset($payload['scope']) ? explode(' ', (string) $payload['scope']) : [],
        ]);

        $tenants = $this->fetchTenants();

        $this->settingsRepository->updateConnectionMeta([
            'connected_at' => now()->toIso8601String(),
            'token_expires_at' => $this->settingsRepository->getToken()?->expires_at?->toIso8601String(),
        ]);

        if ($tenants->count() === 1) {
            $this->settingsRepository->setActiveTenant($tenants->sole()->tenantId);
        }

        $this->flushAccountCache();
    }

    public function disconnect(): void
    {
        $token = $this->settingsRepository->getToken();

        if ($token?->refresh_token) {
            $this->oauthHttp()->asForm()->post((string) config('lunarpanel-xero.oauth.revoke_url'), [
                'token' => $token->refresh_token,
            ]);
        }

        $this->settingsRepository->disconnect();
        $this->flushAccountCache();
    }

    public function fetchTenants(): Collection
    {
        $response = Http::withToken($this->accessToken())->get((string) config('lunarpanel-xero.oauth.connections_url'));

        if ($response->failed()) {
            throw new XeroTransportException('Unable to fetch Xero tenants for the active connection.');
        }

        $tenants = Collection::make($response->json())
            ->map(function (array $tenant): TenantData {
                return new TenantData(
                    tenantId: (string) ($tenant['tenantId'] ?? $tenant['id']),
                    tenantName: (string) ($tenant['tenantName'] ?? $tenant['name']),
                    tenantType: $tenant['tenantType'] ?? null,
                    createdAt: isset($tenant['createdDateUtc']) ? CarbonImmutable::parse($tenant['createdDateUtc']) : null,
                    updatedAt: isset($tenant['updatedDateUtc']) ? CarbonImmutable::parse($tenant['updatedDateUtc']) : null,
                );
            });

        $this->settingsRepository->replaceTenants(
            $tenants->map(fn (TenantData $tenant): array => [
                'tenant_id' => $tenant->tenantId,
                'tenant_name' => $tenant->tenantName,
                'tenant_type' => $tenant->tenantType,
                'connected_at' => $tenant->createdAt,
                'updated_at_xero' => $tenant->updatedAt,
                'payload' => $tenant->toArray(),
                'is_active' => false,
            ])->all(),
        );

        return $tenants;
    }

    public function getAccounts(bool $paymentsOnly = false): Collection
    {
        try {
            $response = $this->accountingApi()->getAccounts($this->tenantId(), null, 'Status=="ACTIVE"', 'Code ASC');
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'fetch accounts from Xero');
        }

        $accounts = Collection::make($this->extractNestedList($response, 'getAccounts'))
            ->map(function ($account): AccountOption {
                /** @var Account $account */
                return new AccountOption(
                    code: (string) $account->getCode(),
                    name: (string) $account->getName(),
                    type: method_exists($account, 'getType') ? $account->getType() : null,
                    class: method_exists($account, 'getClass') ? $account->getClass() : null,
                    enablePaymentsToAccount: (bool) (method_exists($account, 'getEnablePaymentsToAccount') ? $account->getEnablePaymentsToAccount() : false),
                );
            });

        if (! $paymentsOnly) {
            return $accounts;
        }

        return $accounts
            ->filter(fn (AccountOption $account): bool => $this->isPaymentAccount($account))
            ->values();
    }

    protected function isPaymentAccount(AccountOption $account): bool
    {
        if ($account->enablePaymentsToAccount) {
            return true;
        }

        return strtoupper((string) $account->type) === 'BANK';
    }

    public function searchContacts(string $search): Collection
    {
        $response = $this->accountingApi()->getContacts($this->tenantId());

        return Collection::make($this->extractNestedList($response, 'getContacts'))
            ->map(fn (Contact $contact): array => [
                'id' => (string) $contact->getContactID(),
                'name' => (string) ($contact->getName() ?: trim(sprintf('%s %s', $contact->getFirstName(), $contact->getLastName()))),
                'email' => $contact->getEmailAddress(),
            ])
            ->filter(function (array $contact) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([$contact['name'], $contact['email'], $contact['id']])));

                return str_contains($haystack, mb_strtolower($search));
            })
            ->values();
    }

    public function findContactByEmail(string $email): ?array
    {
        return $this->searchContacts($email)
            ->first(fn (array $contact): bool => mb_strtolower((string) $contact['email']) === mb_strtolower($email));
    }

    public function createContact(array $payload): array
    {
        $this->guardWriteOperation('create contacts');

        $contact = new Contact;
        $contact->setName((string) ($payload['name'] ?? $payload['email']));
        $contact->setFirstName($payload['first_name'] ?? null);
        $contact->setLastName($payload['last_name'] ?? null);
        $contact->setEmailAddress($payload['email'] ?? null);

        $addressPayload = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $hasAddress = Collection::make([
            $addressPayload['line_1'] ?? null,
            $addressPayload['line_2'] ?? null,
            $addressPayload['city'] ?? null,
            $addressPayload['region'] ?? null,
            $addressPayload['postal_code'] ?? null,
            $addressPayload['country'] ?? null,
        ])->contains(fn (mixed $value): bool => filled($value));

        if ($hasAddress) {
            $address = new Address;
            $address->setAddressType(Address::ADDRESS_TYPE_STREET);
            $address->setAddressLine1($addressPayload['line_1'] ?? null);
            $address->setAddressLine2($addressPayload['line_2'] ?? null);
            $address->setCity($addressPayload['city'] ?? null);
            $address->setRegion($addressPayload['region'] ?? null);
            $address->setPostalCode($addressPayload['postal_code'] ?? null);
            $address->setCountry($addressPayload['country'] ?? null);

            $contact->setAddresses([$address]);
        }

        if (filled($payload['phone'] ?? null)) {
            $phone = new Phone;
            $phone->setPhoneType(Phone::PHONE_TYPE__DEFAULT);
            $phone->setPhoneNumber((string) $payload['phone']);

            $contact->setPhones([$phone]);
        }

        $contacts = new Contacts;
        $contacts->setContacts([$contact]);

        $response = $this->accountingApi()->createContacts($this->tenantId(), $contacts);
        $created = Collection::make($this->extractNestedList($response, 'getContacts'))->first();

        if (! $created instanceof Contact) {
            throw new XeroTransportException('Xero did not return the created contact.');
        }

        return [
            'id' => (string) $created->getContactID(),
            'name' => (string) $created->getName(),
            'email' => $created->getEmailAddress(),
        ];
    }

    public function findOrCreateItem(array $payload): array
    {
        $this->guardWriteOperation('create items');

        $code = (string) $payload['item_code'];
        $response = $this->accountingApi()->getItems($this->tenantId());
        $existing = Collection::make($this->extractNestedList($response, 'getItems'))
            ->first(fn (Item $item): bool => (string) $item->getCode() === $code);

        if ($existing instanceof Item) {
            return ['item_code' => (string) $existing->getCode()];
        }

        $item = new Item;
        $item->setCode($code);
        $item->setName($this->normalizeItemName(
            (string) ($payload['name'] ?? $payload['description'] ?? $code),
            $code,
        ));
        $item->setDescription($payload['description'] ?? null);

        $items = new Items;
        $items->setItems([$item]);

        $createdResponse = $this->accountingApi()->createItems($this->tenantId(), $items);
        $created = Collection::make($this->extractNestedList($createdResponse, 'getItems'))->first();

        if (! $created instanceof Item) {
            throw new XeroTransportException('Xero did not return the created item.');
        }

        return ['item_code' => (string) $created->getCode()];
    }

    public function createInvoice(InvoicePayload $payload): array
    {
        $this->guardWriteOperation('create invoices');

        try {
            $invoices = new Invoices;
            $invoices->setInvoices([$this->buildInvoiceModel($payload)]);

            $response = $this->accountingApi()->createInvoices(
                $this->tenantId(),
                $invoices,
                false,
                null,
                Str::uuid()->toString(),
            );
            $created = Collection::make($this->extractNestedList($response, 'getInvoices'))->first();
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'create invoice in Xero');
        }

        if (! $created instanceof Invoice) {
            throw new XeroTransportException('Xero did not return the created invoice.');
        }

        return [
            'id' => (string) $created->getInvoiceID(),
            'number' => method_exists($created, 'getInvoiceNumber') ? $created->getInvoiceNumber() : null,
            'status' => method_exists($created, 'getStatus') ? $created->getStatus() : null,
        ];
    }

    public function updateInvoice(string $invoiceId, InvoicePayload $payload): array
    {
        $this->guardWriteOperation('update invoices');

        try {
            $invoice = $this->buildInvoiceModel($payload);
            $invoice->setInvoiceID($invoiceId);

            $invoices = new Invoices;
            $invoices->setInvoices([$invoice]);

            $response = $this->accountingApi()->updateInvoice(
                $this->tenantId(),
                $invoiceId,
                $invoices,
                null,
                Str::uuid()->toString(),
            );
            $updated = Collection::make($this->extractNestedList($response, 'getInvoices'))->first();
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'update invoice in Xero');
        }

        if (! $updated instanceof Invoice) {
            throw new XeroTransportException('Xero did not return the updated invoice.');
        }

        return [
            'id' => (string) $updated->getInvoiceID(),
            'number' => method_exists($updated, 'getInvoiceNumber') ? $updated->getInvoiceNumber() : null,
            'status' => method_exists($updated, 'getStatus') ? $updated->getStatus() : null,
        ];
    }

    public function getOnlineInvoiceUrl(string $invoiceId): ?string
    {
        try {
            $response = $this->accountingApi()->getOnlineInvoice($this->tenantId(), $invoiceId);
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'fetch online invoice URL from Xero');
        }

        $onlineInvoice = Collection::make($this->extractNestedList($response, 'getOnlineInvoices'))->first();

        if (! $onlineInvoice instanceof OnlineInvoice) {
            return null;
        }

        $url = $onlineInvoice->getOnlineInvoiceUrl();

        return filled($url) ? (string) $url : null;
    }

    public function getInvoice(string $invoiceId): array
    {
        try {
            $invoice = $this->fetchInvoice($invoiceId);
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'fetch invoice from Xero');
        }

        if (! $invoice instanceof Invoice) {
            throw new XeroTransportException('Xero did not return the invoice.');
        }

        return [
            'id' => (string) $invoice->getInvoiceID(),
            'number' => $invoice->getInvoiceNumber(),
            'status' => $invoice->getStatus(),
            'sent_to_contact' => (bool) $invoice->getSentToContact(),
        ];
    }

    public function prepareInvoiceEmailRecipients(string $contactId, string $orderEmail): array
    {
        $orderEmailKey = $this->normalizeEmailAddress($orderEmail);

        if ($orderEmailKey === '') {
            throw new XeroTransportException('Unable to prepare invoice email recipients without an order email address.');
        }

        $contact = $this->fetchContact($contactId);

        if (! $contact instanceof Contact) {
            throw new XeroTransportException('Xero did not return the contact for invoice email recipient preparation.');
        }

        $seen = [];
        $recipientCount = 0;
        $duplicateCount = 0;
        $changed = false;
        $orderEmailAdded = false;
        $orderEmailIncluded = false;

        $primaryEmailKey = $this->normalizeEmailAddress($contact->getEmailAddress());

        if ($primaryEmailKey !== '') {
            $seen[$primaryEmailKey] = true;
            $recipientCount++;
            $orderEmailIncluded = $primaryEmailKey === $orderEmailKey;
        }

        $contactPersons = $contact->getContactPersons() ?? [];

        foreach ($contactPersons as $person) {
            $personEmailKey = $this->normalizeEmailAddress($person->getEmailAddress());

            if ($personEmailKey === '') {
                continue;
            }

            $included = (bool) $person->getIncludeInEmails();

            if (! $included && $personEmailKey === $orderEmailKey && ! $orderEmailIncluded) {
                $person->setIncludeInEmails(true);
                $included = true;
                $changed = true;
            }

            if (! $included) {
                continue;
            }

            if (isset($seen[$personEmailKey])) {
                $person->setIncludeInEmails(false);
                $duplicateCount++;
                $changed = true;

                continue;
            }

            $seen[$personEmailKey] = true;
            $recipientCount++;

            if ($personEmailKey === $orderEmailKey) {
                $orderEmailIncluded = true;
            }
        }

        if (! $orderEmailIncluded) {
            $contactPersons[] = (new ContactPerson)
                ->setEmailAddress(trim($orderEmail))
                ->setIncludeInEmails(true);

            $contact->setContactPersons($contactPersons);
            $recipientCount++;
            $changed = true;
            $orderEmailAdded = true;
        }

        if ($changed) {
            $contactUpdate = new Contact;
            $contactUpdate->setContactPersons($contactPersons);

            $contacts = new Contacts;
            $contacts->setContacts([$contactUpdate]);

            $this->guardWriteOperation('update contacts');

            try {
                $this->accountingApi()->updateContact(
                    $this->tenantId(),
                    $contactId,
                    $contacts,
                    Str::uuid()->toString(),
                );
            } catch (Throwable $throwable) {
                throw $this->wrapApiThrowable($throwable, 'prepare invoice email recipients in Xero');
            }
        }

        return [
            'recipient_count' => $recipientCount,
            'changed' => $changed,
            'order_email_added' => $orderEmailAdded,
            'duplicate_count' => $duplicateCount,
        ];
    }

    public function emailInvoice(string $invoiceId): void
    {
        $this->guardWriteOperation('email invoices');

        try {
            $this->accountingApi()->emailInvoice(
                $this->tenantId(),
                $invoiceId,
                new RequestEmpty,
                Str::uuid()->toString(),
            );
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'email invoice from Xero');
        }
    }

    public function createCreditNote(CreditNotePayload $payload): array
    {
        $this->guardWriteOperation('create credit notes');

        try {
            $creditNotes = new CreditNotes;
            $creditNotes->setCreditNotes([$this->buildCreditNoteModel($payload)]);

            $response = $this->accountingApi()->createCreditNotes(
                $this->tenantId(),
                $creditNotes,
                false,
                null,
                Str::uuid()->toString(),
            );
            $created = Collection::make($this->extractNestedList($response, 'getCreditNotes'))->first();
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'create credit note in Xero');
        }

        if (! $created instanceof CreditNote) {
            throw new XeroTransportException('Xero did not return the created credit note.');
        }

        return [
            'id' => (string) $created->getCreditNoteId(),
            'number' => method_exists($created, 'getCreditNoteNumber') ? $created->getCreditNoteNumber() : null,
            'status' => method_exists($created, 'getStatus') ? $created->getStatus() : null,
        ];
    }

    public function findCreditNoteByReference(string $reference): ?array
    {
        try {
            $response = $this->accountingApi()->getCreditNotes($this->tenantId());
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'fetch credit notes from Xero');
        }

        $normalizedReference = mb_strtolower(trim($reference));

        $creditNote = Collection::make($this->extractNestedList($response, 'getCreditNotes'))
            ->first(function ($creditNote) use ($normalizedReference): bool {
                return $creditNote instanceof CreditNote
                    && mb_strtolower(trim((string) $creditNote->getReference())) === $normalizedReference;
            });

        if (! $creditNote instanceof CreditNote) {
            return null;
        }

        return [
            'id' => (string) $creditNote->getCreditNoteId(),
            'number' => method_exists($creditNote, 'getCreditNoteNumber') ? $creditNote->getCreditNoteNumber() : null,
            'status' => method_exists($creditNote, 'getStatus') ? $creditNote->getStatus() : null,
            'allocations' => Collection::make($creditNote->getAllocations() ?? [])
                ->filter(fn (mixed $allocation): bool => $allocation instanceof Allocation)
                ->map(fn (Allocation $allocation): array => [
                    'invoice_id' => $allocation->getInvoice()?->getInvoiceID(),
                    'amount' => round((float) ($allocation->getAmount() ?? 0), 2),
                ])
                ->values()
                ->all(),
            'payments' => Collection::make($creditNote->getPayments() ?? [])
                ->filter(fn (mixed $payment): bool => $payment instanceof Payment)
                ->map(fn (Payment $payment): array => [
                    'reference' => method_exists($payment, 'getReference') ? $payment->getReference() : null,
                    'amount' => round((float) ($payment->getAmount() ?? 0), 2),
                    'account_code' => $payment->getAccount()?->getCode(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function allocateCreditNote(string $creditNoteId, CreditNoteAllocationPayload $payload): array
    {
        $this->guardWriteOperation('allocate credit notes');

        try {
            $invoice = new Invoice;
            $invoice->setInvoiceID($payload->invoiceId);

            $allocation = new Allocation;
            $allocation->setInvoice($invoice);
            $allocation->setAmount($payload->amount);
            $allocation->setDate($payload->date->format('Y-m-d'));

            $allocations = new Allocations;
            $allocations->setAllocations([$allocation]);

            $response = $this->accountingApi()->createCreditNoteAllocation(
                $this->tenantId(),
                $creditNoteId,
                $allocations,
                false,
                Str::uuid()->toString(),
            );
            $created = Collection::make($this->extractNestedList($response, 'getAllocations'))->first();
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'allocate credit note in Xero');
        }

        if (! $created instanceof Allocation) {
            throw new XeroTransportException('Xero did not return the created credit note allocation.');
        }

        return ['id' => method_exists($created, 'getAllocationId') ? $created->getAllocationId() : null];
    }

    public function getInvoicePayments(string $invoiceId): array
    {
        try {
            $invoice = $this->fetchInvoice($invoiceId);
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'fetch invoice payments from Xero');
        }

        if (! $invoice instanceof Invoice) {
            return [];
        }

        return Collection::make($invoice->getPayments() ?? [])
            ->filter(fn (mixed $payment): bool => $payment instanceof Payment)
            ->map(fn (Payment $payment): array => [
                'id' => method_exists($payment, 'getPaymentID') ? $payment->getPaymentID() : null,
                'reference' => method_exists($payment, 'getReference') ? $payment->getReference() : null,
                'amount' => round((float) ($payment->getAmount() ?? 0), 2),
                'date' => method_exists($payment, 'getDate') ? $payment->getDate() : null,
            ])
            ->values()
            ->all();
    }

    public function createPayment(PaymentPayload $payload): array
    {
        $this->guardWriteOperation('create payments');

        try {
            $account = new Account;
            $account->setCode($payload->accountCode);

            $payment = new Payment;

            if ($payload->invoiceId) {
                $invoice = new Invoice;
                $invoice->setInvoiceID($payload->invoiceId);
                $payment->setInvoice($invoice);
            }

            if ($payload->creditNoteId) {
                $creditNote = new CreditNote;
                $creditNote->setCreditNoteId($payload->creditNoteId);
                $payment->setCreditNote($creditNote);
            }

            $payment->setAccount($account);
            $payment->setAmount($payload->amount);
            $payment->setDate($payload->date->format('Y-m-d'));
            $payment->setReference($payload->reference);

            $payments = new Payments;
            $payments->setPayments([$payment]);

            $response = $this->accountingApi()->createPayments(
                $this->tenantId(),
                $payments,
                false,
                Str::uuid()->toString(),
            );
            $created = Collection::make($this->extractNestedList($response, 'getPayments'))->first();
        } catch (Throwable $throwable) {
            throw $this->wrapApiThrowable($throwable, 'create payment in Xero');
        }

        if (! $created instanceof Payment) {
            throw new XeroTransportException('Xero did not return the created payment.');
        }

        return ['id' => (string) $created->getPaymentID()];
    }

    protected function accountingApi(): AccountingApi
    {
        $configuration = Configuration::getDefaultConfiguration()
            ->setAccessToken($this->accessToken());

        return new AccountingApi(new GuzzleClient, $configuration);
    }

    protected function accessToken(): string
    {
        $token = $this->settingsRepository->getToken();

        if (! $token?->access_token) {
            throw new XeroAuthenticationException('No active Xero OAuth token was found for this install.');
        }

        if ($token->expires_at && now()->greaterThanOrEqualTo($token->expires_at->subMinute())) {
            return $this->refreshToken();
        }

        return (string) $token->access_token;
    }

    protected function refreshToken(): string
    {
        $token = $this->settingsRepository->getToken();

        if (! $token?->refresh_token) {
            throw new XeroAuthenticationException('The stored Xero refresh token is missing.');
        }

        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->failed()) {
            throw new XeroAuthenticationException('Refreshing the Xero access token failed.');
        }

        $payload = $response->json();
        $storedToken = $this->settingsRepository->storeToken([
            'access_token' => $payload['access_token'] ?? $token->access_token,
            'refresh_token' => $payload['refresh_token'] ?? $token->refresh_token,
            'id_token' => $payload['id_token'] ?? $token->id_token,
            'token_type' => $payload['token_type'] ?? $token->token_type,
            'expires_at' => isset($payload['expires_in']) ? now()->addSeconds((int) $payload['expires_in']) : $token->expires_at,
            'scopes' => isset($payload['scope']) ? explode(' ', (string) $payload['scope']) : ($token->scopes ?? []),
        ]);

        return (string) $storedToken->access_token;
    }

    protected function tenantId(): string
    {
        $tenant = $this->settingsRepository->getActiveTenant();

        if (! $tenant) {
            throw new XeroConfigurationException('No active Xero tenant has been selected.');
        }

        return $tenant->tenant_id;
    }

    protected function extractNestedList(mixed $response, string $method): array
    {
        if (is_object($response) && method_exists($response, $method)) {
            return $response->{$method}() ?? [];
        }

        return [];
    }

    protected function redirectUri(): string
    {
        return (string) (config('lunarpanel-xero.oauth.redirect_uri') ?: route('lunarpanel-xero.callback'));
    }

    protected function authorizationScopes(): array
    {
        $mode = config('lunarpanel-xero.oauth.read_only', false) ? 'read_scopes' : 'write_scopes';

        return config("lunarpanel-xero.oauth.{$mode}", []);
    }

    protected function oauthHttp()
    {
        return Http::acceptJson()->withBasicAuth(
            (string) config('lunarpanel-xero.oauth.client_id'),
            (string) config('lunarpanel-xero.oauth.client_secret'),
        );
    }

    protected function tokenRequest(array $payload)
    {
        return $this->oauthHttp()
            ->asForm()
            ->post((string) config('lunarpanel-xero.oauth.token_url'), $payload);
    }

    protected function guardOAuthConfiguration(): void
    {
        foreach (['client_id', 'client_secret'] as $key) {
            if (! config("lunarpanel-xero.oauth.{$key}")) {
                throw new XeroConfigurationException("The Xero OAuth setting [{$key}] must be configured.");
            }
        }
    }

    protected function guardWriteOperation(string $operation): void
    {
        if (! config('lunarpanel-xero.oauth.read_only', false)) {
            return;
        }

        throw new XeroConfigurationException(
            "The Xero client is configured for read-only API access and cannot {$operation}.",
        );
    }

    protected function flushAccountCache(): void
    {
        Cache::forget(config('lunarpanel-xero.cache.accounts_key').'.all');
        Cache::forget(config('lunarpanel-xero.cache.accounts_key').'.payments');
        Cache::forget(config('lunarpanel-xero.cache.accounts_key').'.invoice');
    }

    protected function resolveInvoiceDueDate(string $contactId, CarbonImmutable $issueDate): CarbonImmutable
    {
        $contact = $this->fetchContact($contactId);

        if (! $contact instanceof Contact) {
            return $issueDate;
        }

        return $this->calculateDueDateFromContact($contact, $issueDate);
    }

    protected function fetchContact(string $contactId): ?Contact
    {
        try {
            $response = $this->accountingApi()->getContact($this->tenantId(), $contactId);
        } catch (Throwable) {
            return null;
        }

        if ($response instanceof Contact) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'getContacts')) {
            $contact = Collection::make($response->getContacts() ?? [])->first();

            return $contact instanceof Contact ? $contact : null;
        }

        return null;
    }

    protected function fetchInvoice(string $invoiceId): ?Invoice
    {
        $response = $this->accountingApi()->getInvoice($this->tenantId(), $invoiceId);

        if ($response instanceof Invoice) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'getInvoices')) {
            $invoice = Collection::make($response->getInvoices() ?? [])->first();

            return $invoice instanceof Invoice ? $invoice : null;
        }

        return null;
    }

    protected function normalizeEmailAddress(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    protected function calculateDueDateFromContact(Contact $contact, CarbonImmutable $issueDate): CarbonImmutable
    {
        $paymentTerms = $contact->getPaymentTerms();
        $salesTerms = $paymentTerms?->getSales();

        if (! $salesTerms) {
            return $issueDate;
        }

        $type = $salesTerms->getType();
        $day = (int) ($salesTerms->getDay() ?? 0);

        return match ($type) {
            PaymentTermType::DAYSAFTERBILLDATE => $issueDate->addDays(max($day, 0)),
            PaymentTermType::DAYSAFTERBILLMONTH => $issueDate->endOfMonth()->addDays(max($day, 0)),
            PaymentTermType::OFCURRENTMONTH => $this->setDayOfMonth($issueDate, $day),
            PaymentTermType::OFFOLLOWINGMONTH => $this->setDayOfMonth($issueDate->addMonthNoOverflow()->startOfMonth(), $day),
            default => $issueDate,
        };
    }

    protected function setDayOfMonth(CarbonImmutable $date, int $day): CarbonImmutable
    {
        if ($day <= 0) {
            return $date;
        }

        $cappedDay = min($day, $date->daysInMonth);

        return $date->day($cappedDay);
    }

    protected function buildInvoiceModel(InvoicePayload $payload): Invoice
    {
        $invoice = new Invoice;
        $contact = new Contact;
        $contact->setContactID($payload->contactId);
        $issueDate = CarbonImmutable::today();
        $dueDate = $this->resolveInvoiceDueDate($payload->contactId, $issueDate);
        $invoice->setContact($contact);
        $invoice->setType($payload->type);
        $invoice->setDate($issueDate->toDateTime());
        $invoice->setDueDate($dueDate->toDateTime());
        $invoice->setReference($payload->reference);
        $invoice->setStatus($payload->status);

        $lineItems = [];

        foreach ($payload->lines as $line) {
            $lineItem = new LineItem;
            $lineItem->setDescription($line->description);
            $lineItem->setQuantity($line->quantity);
            $lineItem->setUnitAmount($line->unitAmount);
            $lineItem->setAccountCode($line->accountCode);

            if ($line->itemCode) {
                $lineItem->setItemCode($line->itemCode);
            }

            if ($line->taxType) {
                $lineItem->setTaxType($line->taxType);
            }

            $lineItems[] = $lineItem;
        }

        $invoice->setLineItems($lineItems);

        return $invoice;
    }

    protected function buildCreditNoteModel(CreditNotePayload $payload): CreditNote
    {
        $creditNote = new CreditNote;
        $contact = new Contact;
        $contact->setContactID($payload->contactId);
        $creditNote->setContact($contact);
        $creditNote->setType($payload->type);
        $creditNote->setDate($payload->date->format('Y-m-d'));
        $creditNote->setReference($payload->reference);
        $creditNote->setStatus($payload->status);

        $lineItems = [];

        foreach ($payload->lines as $line) {
            $lineItem = new LineItem;
            $lineItem->setDescription($line->description);
            $lineItem->setQuantity($line->quantity);
            $lineItem->setUnitAmount($line->unitAmount);
            $lineItem->setAccountCode($line->accountCode);

            if ($line->itemCode) {
                $lineItem->setItemCode($line->itemCode);
            }

            if ($line->taxType) {
                $lineItem->setTaxType($line->taxType);
            }

            $lineItems[] = $lineItem;
        }

        $creditNote->setLineItems($lineItems);

        return $creditNote;
    }

    protected function wrapApiThrowable(Throwable $throwable, string $action): XeroTransportException
    {
        if ($throwable instanceof ApiException) {
            $message = $this->extractApiExceptionMessage($throwable);

            return new XeroTransportException("Unable to {$action}: {$message}", previous: $throwable);
        }

        return new XeroTransportException("Unable to {$action}: {$throwable->getMessage()}", previous: $throwable);
    }

    protected function extractApiExceptionMessage(ApiException $exception): string
    {
        $responseBody = $exception->getResponseBody();

        if (is_object($responseBody) && method_exists($responseBody, 'jsonSerialize')) {
            $responseBody = $responseBody->jsonSerialize();
        }

        if (is_string($responseBody)) {
            $decoded = json_decode($responseBody, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $responseBody = $decoded;
            }
        }

        if (! is_array($responseBody)) {
            return $exception->getMessage();
        }

        $messages = Collection::make($responseBody['Elements'] ?? [])
            ->flatMap(function (array $element): array {
                $directMessages = Collection::make($element['ValidationErrors'] ?? [])
                    ->pluck('Message')
                    ->filter()
                    ->values()
                    ->all();

                $lineMessages = Collection::make($element['LineItems'] ?? [])
                    ->flatMap(fn (array $line): array => Collection::make($line['ValidationErrors'] ?? [])
                        ->pluck('Message')
                        ->filter()
                        ->values()
                        ->all())
                    ->all();

                return [...$directMessages, ...$lineMessages];
            })
            ->filter()
            ->unique()
            ->values();

        if ($messages->isNotEmpty()) {
            return $messages->implode('; ');
        }

        return (string) ($responseBody['Message'] ?? $exception->getMessage());
    }

    protected function normalizeItemName(string $value, string $fallback): string
    {
        $normalized = trim(Str::limit(trim($value), 50, ''));

        if ($normalized !== '') {
            return $normalized;
        }

        return trim(Str::limit(trim($fallback), 50, ''));
    }
}
