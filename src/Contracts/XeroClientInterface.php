<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Contracts;

use CharlieLangridge\LunarXero\Data\AccountOption;
use CharlieLangridge\LunarXero\Data\CreditNoteAllocationPayload;
use CharlieLangridge\LunarXero\Data\CreditNotePayload;
use CharlieLangridge\LunarXero\Data\InvoicePayload;
use CharlieLangridge\LunarXero\Data\PaymentPayload;
use CharlieLangridge\LunarXero\Data\TenantData;
use Illuminate\Support\Collection;

interface XeroClientInterface
{
    public function getAuthorizationUrl(): string;

    public function handleCallback(string $code, string $state): void;

    public function disconnect(): void;

    /**
     * @return Collection<int, TenantData>
     */
    public function fetchTenants(): Collection;

    /**
     * @return Collection<int, AccountOption>
     */
    public function getAccounts(bool $paymentsOnly = false): Collection;

    /**
     * @return Collection<int, array{id:string,name:string,email:?string}>
     */
    public function searchContacts(string $search): Collection;

    public function findContactByEmail(string $email): ?array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id:string,name:string,email:?string}
     */
    public function createContact(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{item_code:string}
     */
    public function findOrCreateItem(array $payload): array;

    /**
     * @return array{id:string,number:?string,status:?string}
     */
    public function createInvoice(InvoicePayload $payload): array;

    /**
     * @return array{id:string,number:?string,status:?string}
     */
    public function updateInvoice(string $invoiceId, InvoicePayload $payload): array;

    public function getOnlineInvoiceUrl(string $invoiceId): ?string;

    /**
     * @return array{id:string,number:?string,status:?string}
     */
    public function createCreditNote(CreditNotePayload $payload): array;

    /**
     * @return array{id:string,number:?string,status:?string,allocations:array<int, array{invoice_id:?string,amount:float}>,payments:array<int, array{reference:?string,amount:float,account_code:?string}>}|null
     */
    public function findCreditNoteByReference(string $reference): ?array;

    /**
     * @return array{id:?string}
     */
    public function allocateCreditNote(string $creditNoteId, CreditNoteAllocationPayload $payload): array;

    /**
     * @return array<int, array{id:?string,reference:?string,amount:float,date:?string}>
     */
    public function getInvoicePayments(string $invoiceId): array;

    /**
     * @return array{id:string}
     */
    public function createPayment(PaymentPayload $payload): array;
}
