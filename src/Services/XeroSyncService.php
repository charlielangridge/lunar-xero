<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Data\CreditNoteAllocationPayload;
use CharlieLangridge\LunarXero\Data\CreditNotePayload;
use CharlieLangridge\LunarXero\Data\InvoiceLineData;
use CharlieLangridge\LunarXero\Data\InvoicePayload;
use CharlieLangridge\LunarXero\Data\PaymentPayload;
use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Exceptions\XeroConfigurationException;
use CharlieLangridge\LunarXero\Exceptions\XeroSyncException;
use CharlieLangridge\LunarXero\Models\XeroPaymentTypeMapping;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Support\LunarModelResolver;
use CharlieLangridge\LunarXero\Support\XeroItemCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Lunar\Models\Transaction;
use Throwable;

class XeroSyncService
{
    public function __construct(
        protected XeroClientInterface $client,
        protected XeroSettingsRepository $settingsRepository,
        protected LunarModelResolver $modelResolver,
    ) {}

    public function syncOrderInvoiceById(int|string $orderId): array
    {
        $orderClass = $this->modelResolver->orderModel();
        $order = $orderClass::query()->findOrFail($orderId);

        return $this->syncOrderInvoice($order);
    }

    public function syncAndEmailOrderInvoiceById(int|string $orderId): array
    {
        $orderClass = $this->modelResolver->orderModel();
        $order = $orderClass::query()->findOrFail($orderId);

        return $this->syncAndEmailOrderInvoice($order);
    }

    public function syncPaymentById(int|string $paymentId, ?string $paymentClass = null): array
    {
        $paymentClass ??= Transaction::class;
        /** @var Model $payment */
        $payment = $paymentClass::query()->findOrFail($paymentId);

        if ($this->isRefundTransaction($payment)) {
            return $this->syncRefund($payment);
        }

        return $this->syncPayment($payment);
    }

    public function syncCustomerContactById(int|string $customerId): array
    {
        $customerClass = $this->modelResolver->customerModel();
        $customer = $customerClass::query()->findOrFail($customerId);

        return $this->syncCustomerContact($customer);
    }

    public function syncOrderInvoice(Model $order): array
    {
        $log = $this->startLog(
            operation: SyncOperation::Invoice,
            resource: $order,
            payload: ['order_id' => $order->getKey()],
        );

        try {
            $payload = $this->buildOrderInvoicePayload($order);

            if (filled($order->xero_invoice_id) && ! $this->shouldMutateExistingInvoice($order)) {
                $response = [
                    'id' => (string) $order->xero_invoice_id,
                    'status' => 'skipped_existing_locked_invoice',
                ];
                $this->backfillExistingOnlineInvoiceUrl($order, $response);
            } else {
                $response = filled($order->xero_invoice_id)
                    ? $this->client->updateInvoice((string) $order->xero_invoice_id, $payload)
                    : $this->client->createInvoice($payload);

                $this->persistInvoiceDetails($order, $response);
            }

            $paymentResults = $this->syncOrderTransactions($order);

            $this->settingsRepository->updateConnectionMeta([
                'last_invoice_sync_at' => now()->toIso8601String(),
            ]);

            if ($paymentResults !== []) {
                $response['payments'] = $paymentResults;
            }

            return $this->completeLog($log, SyncStatus::Succeeded, $response);
        } catch (Throwable $throwable) {
            $this->failLog($log, $throwable);

            throw $throwable;
        }
    }

    public function syncAndEmailOrderInvoice(Model $order): array
    {
        $log = $this->startLog(
            operation: SyncOperation::InvoiceEmail,
            resource: $order,
            payload: ['order_id' => $order->getKey()],
        );

        try {
            $payload = $this->buildOrderInvoicePayload($order, 'AUTHORISED');

            $invoiceId = data_get($order, 'xero_invoice_id');

            $response = filled($invoiceId)
                ? $this->client->updateInvoice((string) $invoiceId, $payload)
                : $this->client->createInvoice($payload);

            $this->persistInvoiceDetails($order, $response);

            $invoice = $this->client->getInvoice((string) $response['id']);
            $response['sent_to_contact'] = $invoice['sent_to_contact'];

            if ($invoice['sent_to_contact']) {
                $response['email'] = 'skipped_already_sent';

                return $this->completeLog($log, SyncStatus::Succeeded, $response);
            }

            $orderEmail = $this->resolveOrderContactData($order, $this->resolveOrderCustomer($order))['email'] ?? '';
            $response['email_recipients'] = $this->client->prepareInvoiceEmailRecipients($payload->contactId, $orderEmail);

            $this->client->emailInvoice((string) $response['id']);

            $response['email'] = 'sent';

            return $this->completeLog($log, SyncStatus::Succeeded, $response);
        } catch (Throwable $throwable) {
            $this->failLog($log, $throwable);

            throw $throwable;
        }
    }

    public function syncPayment(Model $payment): array
    {
        $externalReference = sprintf('%s:%s', $payment::class, $payment->getKey());
        $existingSuccess = XeroSyncLog::query()
            ->where('operation', SyncOperation::Payment->value)
            ->where('status', SyncStatus::Succeeded->value)
            ->where('external_reference', $externalReference)
            ->exists();

        if ($existingSuccess) {
            return ['id' => 'already-synced'];
        }

        $log = $this->startLog(
            operation: SyncOperation::Payment,
            resource: $payment,
            payload: ['payment_id' => $payment->getKey()],
            externalReference: $externalReference,
        );

        try {
            $order = $this->resolvePaymentOrder($payment);

            if (! filled($order->xero_invoice_id)) {
                throw new XeroSyncException('The Lunar order does not have a synced Xero invoice ID.');
            }

            [$paymentType, $mapping] = $this->resolvePaymentMapping($payment);

            if (! $mapping) {
                throw new XeroConfigurationException("No Xero payment mapping exists for payment type [{$paymentType}].");
            }

            $payload = new PaymentPayload(
                invoiceId: (string) $order->xero_invoice_id,
                creditNoteId: null,
                accountCode: $mapping->account_code,
                amount: $this->moneyToFloat($payment->amount ?? $payment->captured_amount ?? 0),
                date: $this->resolvePaymentDate($payment),
                reference: (string) ($payment->reference ?? $payment->transaction_id ?? $payment->getKey()),
            );

            $existingPayment = $this->findMatchingInvoicePayment((string) $order->xero_invoice_id, $payload);

            if ($existingPayment) {
                return $this->completeLog($log, SyncStatus::Skipped, [
                    'reason' => 'payment_already_exists_in_xero',
                    'id' => $existingPayment['id'] ?? null,
                ]);
            }

            $response = $this->client->createPayment($payload);

            $this->settingsRepository->updateConnectionMeta([
                'last_payment_sync_at' => now()->toIso8601String(),
            ]);

            return $this->completeLog($log, SyncStatus::Succeeded, $response);
        } catch (Throwable $throwable) {
            $this->failLog($log, $throwable);

            throw $throwable;
        }
    }

    public function syncRefund(Model $refund): array
    {
        $log = $this->startLog(
            operation: SyncOperation::CreditNote,
            resource: $refund,
            payload: ['refund_id' => $refund->getKey()],
            externalReference: sprintf('%s:%s', $refund::class, $refund->getKey()),
        );

        try {
            $order = $this->resolvePaymentOrder($refund);

            if (! filled($order->xero_invoice_id)) {
                throw new XeroSyncException('The Lunar order does not have a synced Xero invoice ID.');
            }

            $payload = new CreditNotePayload(
                contactId: $this->resolveCustomerContactId($order),
                reference: $this->resolveRefundReference($order, $refund),
                lines: $this->buildCreditNoteLines($order, $refund),
                date: $this->resolveRefundDate($refund),
            );

            $creditNote = $this->client->findCreditNoteByReference($payload->reference)
                ?? $this->client->createCreditNote($payload);

            $refundAmount = abs($this->moneyToFloat($refund->amount ?? 0));

            $allocation = $this->creditNoteAlreadyAllocatedToInvoice($creditNote, (string) $order->xero_invoice_id)
                ? ['id' => 'already-allocated']
                : $this->client->allocateCreditNote(
                    (string) $creditNote['id'],
                    new CreditNoteAllocationPayload(
                        invoiceId: (string) $order->xero_invoice_id,
                        amount: $refundAmount,
                        date: $payload->date,
                    ),
                );

            [$paymentType, $mapping] = $this->resolvePaymentMapping($refund);

            if (! $mapping) {
                throw new XeroConfigurationException("No Xero payment mapping exists for payment type [{$paymentType}].");
            }

            $creditPayment = $this->creditNoteAlreadyPaidOut($creditNote, $payload->reference, $refundAmount, $mapping->account_code)
                ? ['id' => 'already-paid']
                : $this->client->createPayment(new PaymentPayload(
                    invoiceId: null,
                    creditNoteId: (string) $creditNote['id'],
                    accountCode: $mapping->account_code,
                    amount: $refundAmount,
                    date: $payload->date,
                    reference: $payload->reference,
                ));

            $response = [
                'credit_note' => $creditNote,
                'allocation' => $allocation,
                'payment' => $creditPayment,
            ];

            $this->settingsRepository->updateConnectionMeta([
                'last_credit_note_sync_at' => now()->toIso8601String(),
            ]);

            return $this->completeLog($log, SyncStatus::Succeeded, $response);
        } catch (Throwable $throwable) {
            $this->failLog($log, $throwable);

            throw $throwable;
        }
    }

    public function linkCustomer(Model $customer, string $contactId): Model
    {
        $customer->forceFill(['xero_contact_id' => $contactId])->save();

        return $customer;
    }

    public function syncCustomerContact(Model $customer): array
    {
        $log = $this->startLog(
            operation: SyncOperation::Contact,
            resource: $customer,
            payload: ['customer_id' => $customer->getKey()],
        );

        try {
            if (filled($customer->xero_contact_id)) {
                return $this->completeLog($log, SyncStatus::Skipped, ['reason' => 'contact_already_linked']);
            }

            $email = $this->resolveCustomerEmail($customer);

            if ($email === '') {
                return $this->completeLog($log, SyncStatus::Skipped, ['reason' => 'no_customer_email']);
            }

            $existing = $this->client->findContactByEmail($email);

            if (! $existing) {
                return $this->completeLog($log, SyncStatus::Skipped, ['reason' => 'no_matching_xero_contact']);
            }

            $this->persistCustomerContactId($customer, $existing['id']);

            return $this->completeLog($log, SyncStatus::Succeeded, $existing);
        } catch (Throwable $throwable) {
            $this->failLog($log, $throwable);

            throw $throwable;
        }
    }

    protected function resolveCustomerContactId(Model $order): string
    {
        $customer = $this->resolveOrderCustomer($order);
        $contact = $this->resolveOrderContactData($order, $customer);

        if ($customer && filled($customer->xero_contact_id)) {
            return (string) $customer->xero_contact_id;
        }

        $email = (string) ($contact['email'] ?? '');

        if ($email === '') {
            throw new XeroSyncException('Unable to resolve a customer email address for Xero contact creation.');
        }

        $existing = $this->client->findContactByEmail($email);

        if ($existing) {
            $this->persistCustomerContactId($customer, $existing['id']);

            return $existing['id'];
        }

        $created = $this->client->createContact([
            'email' => $email,
            'name' => $contact['name'],
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'company_name' => $contact['company_name'],
            'phone' => $contact['phone'],
            'address' => $contact['address'],
        ]);

        $this->persistCustomerContactId($customer, $created['id']);

        return $created['id'];
    }

    protected function buildInvoiceLines(Model $order): array
    {
        $lines = Collection::make($order->lines ?? $order->lines()->get());

        if ($lines->isEmpty()) {
            throw new XeroSyncException('Cannot create a Xero invoice for an order without lines.');
        }

        return $lines->map(function ($line) use ($order): InvoiceLineData {
            [$variant, $product] = $this->resolveLineContext($line);
            $accountCode = $this->resolveAccountCode($variant, $product);
            $itemCode = $this->resolveItemCode($line, $variant, $product);

            return new InvoiceLineData(
                description: $this->resolveInvoiceLineDescription($line, $variant, $product, $order),
                quantity: (float) ($line->quantity ?? 1),
                unitAmount: $this->moneyToFloat($line->unit_price ?? $line->sub_total ?? 0),
                accountCode: $accountCode,
                itemCode: $itemCode,
                taxType: $this->resolveLineTaxType($line),
            );
        })->pipe(function (Collection $lines) use ($order): array {
            foreach ($this->buildCharityTraceabilityLines($order, $lines) as $charityLine) {
                $lines->push($charityLine);
            }

            $purchaseOrderLine = $this->buildPurchaseOrderLine($order, $lines);

            if ($purchaseOrderLine) {
                $lines->push($purchaseOrderLine);
            }

            return $lines->all();
        });
    }

    protected function resolveItemCode(mixed $line, ?Model $variant, ?Model $product): ?string
    {
        if ($variant && filled($variant->xero_item_code)) {
            $itemCode = XeroItemCode::explicit((string) $variant->xero_item_code);

            if (XeroItemCode::isGeneratedForSku($itemCode, $variant->sku ?? null)) {
                if ($product && filled($product->xero_item_code)) {
                    $variant->forceFill(['xero_item_code' => null])->save();

                    return XeroItemCode::explicit((string) $product->xero_item_code);
                }

                return $this->ensureCatalogItemExists($variant, $itemCode, $line, $variant, $product);
            }

            return $itemCode;
        }

        if ($product && filled($product->xero_item_code)) {
            return XeroItemCode::explicit((string) $product->xero_item_code);
        }

        if ($variant && filled($variant->sku)) {
            $itemCode = XeroItemCode::fallbackForSku((string) $variant->sku);

            if ($itemCode === null) {
                return null;
            }

            return $this->ensureCatalogItemExists($variant, $itemCode, $line, $variant, $product);
        }

        $source = $variant ?? $product;

        if (! $source) {
            return null;
        }

        $proposedCode = (string) ($variant?->sku ?? $product?->attribute_data['name'] ?? 'item-'.$source->getKey());
        $itemCode = XeroItemCode::fallbackForSku($proposedCode);

        if ($itemCode === null || XeroItemCode::shouldGenerateForSku($proposedCode)) {
            return null;
        }

        return $this->ensureCatalogItemExists($source, $itemCode, $line, $variant, $product);
    }

    protected function resolveAccountCode(?Model $variant, ?Model $product): string
    {
        $accountCode = $variant?->xero_account_code
            ?? $product?->xero_account_code
            ?? $this->settingsRepository->getDefaultAccountCode();

        if (! $accountCode) {
            throw new XeroConfigurationException('No Xero account code could be resolved for the order line.');
        }

        return (string) $accountCode;
    }

    protected function resolveOrderCustomer(Model $order): ?Model
    {
        if (($order->customer ?? null) instanceof Model) {
            return $order->customer;
        }

        if (method_exists($order, 'customer')) {
            /** @var mixed $relation */
            $relation = $order->customer();

            if ($relation instanceof Relation) {
                return $relation->first();
            }
        }

        return null;
    }

    protected function resolvePaymentOrder(Model $payment): Model
    {
        if (($payment->order ?? null) instanceof Model) {
            return $payment->order;
        }

        if (method_exists($payment, 'order')) {
            return $payment->order()->firstOrFail();
        }

        throw new XeroSyncException('Unable to resolve the Lunar order for the payment.');
    }

    protected function resolvePaymentType(Model $payment): string
    {
        foreach ($this->paymentTypeCandidates($payment) as $candidate) {
            return $candidate;
        }

        return 'default';
    }

    /**
     * @return array{0:string,1:?XeroPaymentTypeMapping}
     */
    protected function resolvePaymentMapping(Model $payment): array
    {
        $candidates = $this->paymentTypeCandidates($payment);

        foreach ($candidates as $candidate) {
            $mapping = $this->settingsRepository->findPaymentMapping($candidate);

            if ($mapping) {
                return [$candidate, $mapping];
            }
        }

        return [$candidates[0] ?? 'default', null];
    }

    /**
     * @return array<int, string>
     */
    protected function paymentTypeCandidates(Model $payment): array
    {
        $candidates = [];

        foreach ([
            $payment->payment_type ?? null,
            $payment->driver ?? null,
            $payment->gateway ?? null,
            $payment->type ?? null,
        ] as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);

            if ($value !== '') {
                $candidates[] = strtolower($value);
            }
        }

        if (in_array('stripe', $candidates, true)) {
            $candidates[] = 'card';
        }

        $candidates[] = 'default';

        return array_values(array_unique($candidates));
    }

    protected function resolveOrderPayments(Model $order): Collection
    {
        foreach (['transactions', 'payments'] as $relationName) {
            $loaded = $order->{$relationName} ?? null;

            if ($loaded instanceof Collection) {
                return $loaded;
            }

            if (method_exists($order, $relationName)) {
                /** @var mixed $relation */
                $relation = $order->{$relationName}();

                if ($relation instanceof Relation) {
                    return $relation->get();
                }
            }
        }

        return collect();
    }

    /**
     * @return array<int, InvoiceLineData>
     */
    protected function buildCreditNoteLines(Model $order, Model $refund): array
    {
        $orderLines = Collection::make($order->lines ?? $order->lines()->get());
        $refundAmount = abs($this->moneyToFloat($refund->amount ?? 0));

        if ($refundAmount <= 0.0) {
            throw new XeroSyncException('Refund amount must be greater than zero to create a Xero credit note.');
        }

        if ($orderLines->isEmpty()) {
            return [$this->buildFallbackCreditNoteLine($order, $refund, $refundAmount)];
        }

        $grossTotal = round($orderLines->sum(fn ($line): float => $this->resolveLineGrossAmount($line)), 2);

        if ($grossTotal <= 0.0) {
            return [$this->buildFallbackCreditNoteLine($order, $refund, $refundAmount)];
        }

        $ratio = $refundAmount / $grossTotal;

        $lines = $orderLines
            ->map(function ($line) use ($order, $ratio): ?InvoiceLineData {
                $grossAmount = $this->resolveLineGrossAmount($line);

                if ($grossAmount <= 0.0) {
                    return null;
                }

                [$variant, $product] = $this->resolveLineContext($line);
                $netAmount = round($this->resolveLineNetAmount($line) * $ratio, 2);

                if ($netAmount <= 0.0) {
                    return null;
                }

                return new InvoiceLineData(
                    description: $this->resolveInvoiceLineDescription($line, $variant, $product, $order),
                    quantity: 1.0,
                    unitAmount: $netAmount,
                    accountCode: $this->resolveAccountCode($variant, $product),
                    itemCode: $this->resolveItemCode($line, $variant, $product),
                    taxType: $this->resolveLineTaxType($line),
                );
            })
            ->filter()
            ->values();

        if ($lines->isEmpty()) {
            return [$this->buildFallbackCreditNoteLine($order, $refund, $refundAmount)];
        }

        return $lines->all();
    }

    protected function resolvePaymentDate(Model $payment): CarbonInterface
    {
        foreach (['captured_at', 'authorized_at', 'created_at'] as $column) {
            if ($payment->{$column} instanceof CarbonInterface) {
                return $payment->{$column};
            }
        }

        return now();
    }

    protected function startLog(SyncOperation $operation, Model $resource, array $payload, ?string $externalReference = null): XeroSyncLog
    {
        return XeroSyncLog::query()->create([
            'operation' => $operation->value,
            'status' => SyncStatus::Processing->value,
            'resource_type' => $resource::class,
            'resource_id' => $resource->getKey(),
            'external_reference' => $externalReference,
            'payload' => $payload,
            'attempt' => 1,
            'started_at' => now(),
        ]);
    }

    protected function buildOrderInvoicePayload(Model $order, ?string $status = null): InvoicePayload
    {
        return new InvoicePayload(
            contactId: $this->resolveCustomerContactId($order),
            status: $status ?? $this->settingsRepository->getInvoiceStatus(),
            reference: $this->resolveInvoiceReference($order),
            lines: $this->buildInvoiceLines($order),
        );
    }

    protected function persistInvoiceDetails(Model $order, array &$response): void
    {
        $invoiceId = (string) $response['id'];
        $invoiceStatus = $response['status'] ?? null;
        $onlineInvoiceUrl = null;

        if ($this->shouldFetchOnlineInvoiceUrl($invoiceStatus)) {
            try {
                $onlineInvoiceUrl = $this->client->getOnlineInvoiceUrl($invoiceId);
            } catch (Throwable $throwable) {
                $response['online_invoice_url_error'] = $throwable->getMessage();
            }
        }

        $order->forceFill([
            'xero_invoice_id' => $invoiceId,
            'xero_invoice_number' => $response['number'] ?? null,
            'xero_invoice_status' => $invoiceStatus,
            'xero_online_invoice_url' => $onlineInvoiceUrl,
        ])->save();

        $response['online_invoice_url'] = $onlineInvoiceUrl;
    }

    protected function backfillExistingOnlineInvoiceUrl(Model $order, array &$response): void
    {
        if (filled(data_get($order, 'xero_online_invoice_url')) || ! $this->shouldFetchOnlineInvoiceUrl(data_get($order, 'xero_invoice_status'))) {
            return;
        }

        try {
            $onlineInvoiceUrl = $this->client->getOnlineInvoiceUrl((string) data_get($order, 'xero_invoice_id'));
        } catch (Throwable $throwable) {
            $response['online_invoice_url_error'] = $throwable->getMessage();

            return;
        }

        $order->forceFill(['xero_online_invoice_url' => $onlineInvoiceUrl])->save();
        $response['online_invoice_url'] = $onlineInvoiceUrl;
    }

    protected function shouldFetchOnlineInvoiceUrl(?string $invoiceStatus): bool
    {
        return in_array(mb_strtoupper(trim((string) $invoiceStatus)), ['AUTHORISED', 'PAID'], true);
    }

    protected function completeLog(XeroSyncLog $log, SyncStatus $status, array $response): array
    {
        $log->forceFill([
            'status' => $status->value,
            'response' => $response,
            'completed_at' => now(),
        ])->save();

        return $response;
    }

    protected function failLog(XeroSyncLog $log, Throwable $throwable): void
    {
        $log->forceFill([
            'status' => SyncStatus::Failed->value,
            'error_message' => $throwable->getMessage(),
            'context' => [
                'exception' => $throwable::class,
            ],
            'completed_at' => now(),
        ])->save();
    }

    protected function resolveLineContext(mixed $line): array
    {
        $variant = null;
        $product = null;

        $purchasableType = is_object($line) ? ($line->purchasable_type ?? null) : null;
        $resolvedPurchasableClass = is_string($purchasableType)
            ? (Relation::getMorphedModel($purchasableType) ?? $purchasableType)
            : null;

        if (
            is_string($resolvedPurchasableClass)
            && class_exists($resolvedPurchasableClass)
            && is_subclass_of($resolvedPurchasableClass, Model::class)
        ) {
            $purchasable = $line->purchasable ?? null;
            $variant = $purchasable instanceof Model ? $purchasable : null;
        }

        if (! $variant && ($line->variant ?? null) instanceof Model) {
            $variant = $line->variant;
        }

        if ($variant && method_exists($variant, 'product')) {
            $product = $variant->product;
        }

        if (! $variant && ($line->product ?? null) instanceof Model) {
            $product = $line->product;
        }

        return [$variant, $product];
    }

    protected function moneyToFloat(mixed $value): float
    {
        if (is_object($value) && method_exists($value, 'unitDecimal')) {
            return round((float) $value->unitDecimal(), 2);
        }

        if (is_object($value) && method_exists($value, 'decimal')) {
            return round((float) $value->decimal(), 2);
        }

        if (is_object($value) && isset($value->value)) {
            $value = $value->value;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (is_string($value)) {
            return round((float) $value, 2);
        }

        return 0.0;
    }

    protected function syncOrderTransactions(Model $order): array
    {
        if (! filled($order->xero_invoice_id)) {
            return [];
        }

        return $this->resolveOrderPayments($order)
            ->filter(fn (mixed $payment): bool => $payment instanceof Model && $this->shouldSyncOrderTransaction($payment))
            ->map(function (Model $payment): array {
                try {
                    return [
                        'payment_id' => (string) $payment->getKey(),
                        'status' => 'synced',
                        'result' => $this->syncPaymentById($payment->getKey(), $payment::class),
                    ];
                } catch (Throwable $throwable) {
                    return [
                        'payment_id' => (string) $payment->getKey(),
                        'status' => 'failed',
                        'error' => $throwable->getMessage(),
                    ];
                }
            })
            ->values()
            ->all();
    }

    protected function shouldSyncOrderTransaction(Model $payment): bool
    {
        if ($this->isRefundTransaction($payment)) {
            return isset($payment->success) ? (bool) $payment->success : true;
        }

        return $this->shouldSyncPayment($payment);
    }

    protected function shouldMutateExistingInvoice(Model $order): bool
    {
        return ! $this->resolveOrderPayments($order)
            ->contains(fn (mixed $payment): bool => $payment instanceof Model && $this->isCommittedTransaction($payment));
    }

    protected function isCommittedTransaction(Model $payment): bool
    {
        if (isset($payment->success) && ! (bool) $payment->success) {
            return false;
        }

        if ($this->isRefundTransaction($payment)) {
            return true;
        }

        if ($payment->captured_at instanceof CarbonInterface) {
            return true;
        }

        return strtolower(trim((string) ($payment->type ?? ''))) === 'capture';
    }

    protected function shouldSyncPayment(Model $payment): bool
    {
        if (isset($payment->success) && ! (bool) $payment->success) {
            return false;
        }

        $type = strtolower(trim((string) ($payment->type ?? '')));

        if ($type !== '' && in_array($type, ['intent', 'refund'], true)) {
            return false;
        }

        if ($payment->captured_at instanceof CarbonInterface) {
            return true;
        }

        return $type === 'capture' || isset($payment->success);
    }

    protected function isRefundTransaction(Model $payment): bool
    {
        return strtolower(trim((string) ($payment->type ?? ''))) === 'refund';
    }

    protected function resolveInvoiceLineDescription(mixed $line, ?Model $variant, ?Model $product, ?Model $order = null): string
    {
        $catalogItemName = $this->resolveCatalogItemName($line, $variant, $product);

        if ($variant) {
            return $this->appendOrderLineNotes($catalogItemName, $line, $order);
        }

        $description = $this->firstFilledString(
            is_object($line) ? ($line->description ?? null) : null,
            is_object($line) ? ($line->identifier ?? null) : null,
            $catalogItemName,
            'Order line',
        );

        return $this->appendOrderLineNotes($description, $line, $order);
    }

    protected function appendOrderLineNotes(string $description, mixed $line, ?Model $order): string
    {
        if (! $this->shouldIncludeOrderLineNotes($order)) {
            return $description;
        }

        $notes = is_object($line) ? trim((string) ($line->notes ?? '')) : '';

        if ($notes === '') {
            return $description;
        }

        return "{$description}\nNotes: {$notes}";
    }

    protected function shouldIncludeOrderLineNotes(?Model $order): bool
    {
        if (! $order) {
            return false;
        }

        $customer = $this->resolveOrderCustomer($order);

        return (bool) data_get($customer, 'xero_include_order_line_notes', false);
    }

    protected function resolveCatalogItemName(mixed $line, ?Model $variant, ?Model $product): string
    {
        if ($variant) {
            $productName = $this->firstFilledString(
                method_exists($variant, 'getDescription') ? $variant->getDescription() : null,
                $product?->attribute_data['name'] ?? null,
            );
            $optionName = $this->firstFilledString(
                method_exists($variant, 'getOption') ? $variant->getOption() : null,
                $variant->option_values ?? null,
            );

            return trim(implode(' - ', array_filter([$productName, $optionName])));
        }

        return $this->firstFilledString(
            is_object($line) ? ($line->description ?? null) : null,
            is_object($line) ? ($line->identifier ?? null) : null,
            $product?->attribute_data['name'] ?? null,
            'Order line',
        );
    }

    protected function normalizeItemCode(string $value): string
    {
        return XeroItemCode::normalize($value);
    }

    protected function ensureCatalogItemExists(
        Model $source,
        string $itemCode,
        mixed $line,
        ?Model $variant,
        ?Model $product,
    ): string {
        $created = $this->client->findOrCreateItem([
            'item_code' => $itemCode,
            'name' => $this->resolveCatalogItemName($line, $variant, $product),
            'description' => $this->resolveInvoiceLineDescription($line, $variant, $product),
        ]);

        if (($source->xero_item_code ?? null) !== $created['item_code']) {
            $source->forceFill(['xero_item_code' => $created['item_code']])->save();
        }

        return $created['item_code'];
    }

    protected function resolveLineTaxType(mixed $line): ?string
    {
        $breakdown = is_object($line) ? ($line->tax_breakdown ?? null) : null;
        $amounts = $breakdown?->amounts ?? null;

        if ($amounts instanceof Collection) {
            $percentage = $amounts
                ->map(fn ($amount): ?float => isset($amount->percentage) ? (float) $amount->percentage : null)
                ->filter(fn (?float $value): bool => $value !== null)
                ->first();

            if ($percentage !== null) {
                if (abs($percentage) < 0.0001) {
                    return 'ZERORATEDOUTPUT';
                }

                if (abs($percentage - 20.0) < 0.0001) {
                    return 'OUTPUT2';
                }
            }
        }

        $taxTotal = $this->moneyToFloat(is_object($line) ? ($line->tax_total ?? 0) : 0);

        if (abs($taxTotal) < 0.0001) {
            return 'ZERORATEDOUTPUT';
        }

        return 'OUTPUT2';
    }

    protected function buildPurchaseOrderLine(Model $order, Collection $lines): ?InvoiceLineData
    {
        $purchaseOrderReference = $this->resolvePurchaseOrderReference($order);

        if ($purchaseOrderReference === '') {
            return null;
        }

        $accountCode = $lines->first() instanceof InvoiceLineData
            ? $lines->first()->accountCode
            : (string) ($this->settingsRepository->getDefaultAccountCode() ?? '');

        if ($accountCode === '') {
            return null;
        }

        return new InvoiceLineData(
            description: "Purchase Order: {$purchaseOrderReference}",
            quantity: 1,
            unitAmount: 0.0,
            accountCode: $accountCode,
            taxType: 'ZERORATEDOUTPUT',
        );
    }

    /**
     * @return array<int, InvoiceLineData>
     */
    protected function buildCharityTraceabilityLines(Model $order, Collection $lines): array
    {
        if (! (bool) config('lunarpanel-xero.charity.enabled', true)) {
            return [];
        }

        $accountCode = $this->resolveMetadataLineAccountCode($lines);

        if ($accountCode === null) {
            return [];
        }

        $charityData = $this->resolveCharityTraceabilityData($order);

        $definitions = [
            ['label' => 'Charity name', 'value' => $charityData['charity_name']],
            ['label' => 'Charity number', 'value' => $charityData['charity_number']],
            ['label' => 'Declaration name', 'value' => $charityData['declaration_name']],
            ['label' => 'Declared at', 'value' => $charityData['declared_at']],
        ];

        return collect($definitions)
            ->filter(fn (array $definition): bool => $definition['value'] !== '')
            ->map(fn (array $definition): InvoiceLineData => new InvoiceLineData(
                description: "{$definition['label']}: {$definition['value']}",
                quantity: 1.0,
                unitAmount: 0.0,
                accountCode: $accountCode,
                taxType: 'ZERORATEDOUTPUT',
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{charity_name:string,charity_number:string,declaration_name:string,declared_at:string}
     */
    protected function resolveCharityTraceabilityData(Model $order): array
    {
        return [
            'charity_name' => $this->resolveConfiguredOrderString($order, 'name_path'),
            'charity_number' => $this->resolveConfiguredOrderString($order, 'number_path'),
            'declaration_name' => $this->resolveConfiguredOrderString($order, 'declaration_name_path'),
            'declared_at' => $this->resolveFormattedCharityDeclaredAt($order),
        ];
    }

    protected function resolveConfiguredOrderString(Model $order, string $configKey): string
    {
        $path = config("lunarpanel-xero.charity.{$configKey}");

        if (! is_string($path) || trim($path) === '') {
            return '';
        }

        return $this->firstFilledString(data_get($order, $path));
    }

    protected function resolveFormattedCharityDeclaredAt(Model $order): string
    {
        $path = config('lunarpanel-xero.charity.declared_at_path');

        if (! is_string($path) || trim($path) === '') {
            return '';
        }

        $value = data_get($order, $path);

        if (! is_scalar($value) || trim((string) $value) === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse((string) $value)
                ->locale('en_GB')
                ->translatedFormat('j F Y, g:i a');
        } catch (Throwable) {
            return '';
        }
    }

    protected function resolveMetadataLineAccountCode(Collection $lines): ?string
    {
        $accountCode = $lines->first() instanceof InvoiceLineData
            ? $lines->first()->accountCode
            : (string) ($this->settingsRepository->getDefaultAccountCode() ?? '');

        $accountCode = trim($accountCode);

        return $accountCode !== '' ? $accountCode : null;
    }

    protected function persistCustomerContactId(?Model $customer, string $contactId): void
    {
        if (! $customer) {
            return;
        }

        $customer->forceFill(['xero_contact_id' => $contactId])->save();
    }

    protected function resolveOrderContactData(Model $order, ?Model $customer): array
    {
        $billingAddress = $this->resolveOrderAddress($order, 'billing');
        $shippingAddress = $this->resolveOrderAddress($order, 'shipping');
        $address = $billingAddress ?? $shippingAddress;
        $contactAddress = $billingAddress ?? $shippingAddress;

        $preferAddress = $customer === null
            || ! filled($customer->xero_contact_id ?? null)
            || (
                $this->firstFilledString(
                    $customer->first_name ?? null,
                    $customer->last_name ?? null,
                    $customer->full_name ?? null,
                    $customer->name ?? null,
                    $customer->company_name ?? null,
                ) === ''
            );

        $email = $this->firstFilledString(
            $address?->contact_email,
            $customer?->email,
            $order->email ?? null,
        );

        $firstName = $preferAddress
            ? $this->firstFilledString($address?->first_name, $customer?->first_name)
            : $this->firstFilledString($customer?->first_name, $address?->first_name);

        $lastName = $preferAddress
            ? $this->firstFilledString($address?->last_name, $customer?->last_name)
            : $this->firstFilledString($customer?->last_name, $address?->last_name);

        $companyName = $preferAddress
            ? $this->firstFilledString($address?->company_name, $customer?->company_name)
            : $this->firstFilledString($customer?->company_name, $address?->company_name);

        $name = $this->firstFilledString(
            $address?->company_name ?? null,
            $address?->fullName ?? null,
            trim(implode(' ', array_filter([$address?->first_name ?? null, $address?->last_name ?? null]))),
            $companyName,
            $customer?->full_name ?? null,
            $customer?->name ?? null,
            trim(implode(' ', array_filter([$firstName, $lastName]))),
            $order->customer_name ?? null,
            $email,
        );

        return [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'company_name' => $companyName,
            'phone' => $this->firstFilledString(
                $contactAddress?->contact_phone,
                $customer?->phone ?? null,
            ),
            'address' => [
                'line_1' => $this->firstFilledString($contactAddress?->line_one),
                'line_2' => $this->firstFilledString($contactAddress?->line_two, $contactAddress?->line_three),
                'city' => $this->firstFilledString($contactAddress?->city),
                'region' => $this->firstFilledString($contactAddress?->state),
                'postal_code' => $this->firstFilledString($contactAddress?->postcode),
                'country' => $this->resolveAddressCountryName($contactAddress),
            ],
        ];
    }

    protected function resolveOrderAddress(Model $order, string $type): ?Model
    {
        $loadedAddresses = $order->addresses ?? null;

        if ($loadedAddresses instanceof Collection) {
            $address = $loadedAddresses->first(fn ($address): bool => ($address->type ?? null) === $type);

            if ($address instanceof Model) {
                return $address;
            }
        }

        $property = $type.'Address';

        if (($order->{$property} ?? null) instanceof Model) {
            return $order->{$property};
        }

        if (method_exists($order, $property)) {
            /** @var mixed $relation */
            $relation = $order->{$property}();

            if ($relation instanceof Relation) {
                return $relation->first();
            }
        }

        return null;
    }

    protected function firstFilledString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);

            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    protected function distinctFilledStrings(mixed ...$values): array
    {
        $strings = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);

            if ($string === '') {
                continue;
            }

            $key = mb_strtolower($string);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $strings[] = $string;
        }

        return $strings;
    }

    protected function resolveCustomerEmail(Model $customer): string
    {
        $users = $customer->users ?? null;
        $addresses = $customer->addresses ?? null;
        $orders = $customer->orders ?? null;

        $userEmail = $users instanceof Collection
            ? $users->pluck('email')->filter()->map(fn ($email) => trim((string) $email))->first() ?? ''
            : '';

        $addressEmail = $addresses instanceof Collection
            ? $addresses->pluck('contact_email')->filter()->map(fn ($email) => trim((string) $email))->first()
                ?? $addresses->pluck('contact_mail')->filter()->map(fn ($email) => trim((string) $email))->first()
                ?? ''
            : '';

        $orderEmail = '';

        if ($orders instanceof Collection) {
            $orderEmail = $orders
                ->map(fn ($order) => $this->resolveOrderContactData($order, $customer)['email'] ?? '')
                ->filter()
                ->first() ?? '';
        }

        return $this->firstFilledString($userEmail, $addressEmail, $orderEmail);
    }

    protected function resolveAddressCountryName(?Model $address): string
    {
        if (! $address) {
            return '';
        }

        $country = $address->country ?? null;

        if ($country instanceof Model) {
            return $this->firstFilledString($country->name ?? null);
        }

        if (method_exists($address, 'country')) {
            /** @var mixed $relation */
            $relation = $address->country();

            if ($relation instanceof Relation) {
                $country = $relation->first();

                if ($country instanceof Model) {
                    return $this->firstFilledString($country->name ?? null);
                }
            }
        }

        return $this->firstFilledString(
            $address->country_name ?? null,
            is_scalar($address->country ?? null) ? $address->country : null,
        );
    }

    protected function findMatchingInvoicePayment(string $invoiceId, PaymentPayload $payload): ?array
    {
        $reference = mb_strtolower(trim($payload->reference));
        $amount = round($payload->amount, 2);
        $date = $payload->date->format('Y-m-d');

        return collect($this->client->getInvoicePayments($invoiceId))
            ->first(function (array $payment) use ($reference, $amount, $date): bool {
                $paymentReference = mb_strtolower(trim((string) ($payment['reference'] ?? '')));
                $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);
                $paymentDate = substr((string) ($payment['date'] ?? ''), 0, 10);

                if ($paymentReference !== '' && $paymentReference === $reference) {
                    return true;
                }

                return $paymentAmount === $amount && $paymentDate === $date;
            });
    }

    protected function resolveInvoiceReference(Model $order): string
    {
        $parts = $this->distinctFilledStrings(
            $order->reference ?? null,
            $this->resolvePurchaseOrderReference($order),
            $order->customer_reference ?? null,
        );

        return $parts !== []
            ? implode(' - ', $parts)
            : (string) $order->getKey();
    }

    protected function resolvePurchaseOrderReference(Model $order): string
    {
        return $this->firstFilledString(
            data_get($order, 'meta.purchase_order'),
            $order->customer_reference ?? null,
        );
    }

    protected function resolveRefundReference(Model $order, Model $refund): string
    {
        return $this->firstFilledString(
            is_scalar($refund->reference ?? null) ? 'Refund '.$refund->reference : null,
            is_scalar($order->reference ?? null) ? 'Refund '.$order->reference : null,
            'Refund '.$refund->getKey(),
        );
    }

    protected function resolveRefundDate(Model $refund): CarbonInterface
    {
        foreach (['refunded_at', 'captured_at', 'created_at'] as $column) {
            if ($refund->{$column} instanceof CarbonInterface) {
                return $refund->{$column};
            }
        }

        return now();
    }

    protected function resolveLineNetAmount(mixed $line): float
    {
        $quantity = max((float) (is_object($line) ? ($line->quantity ?? 1) : 1), 1.0);

        $subTotal = $this->moneyToFloat(is_object($line) ? ($line->sub_total ?? null) : null);

        if ($subTotal > 0.0) {
            return $subTotal;
        }

        return round($this->moneyToFloat(is_object($line) ? ($line->unit_price ?? 0) : 0) * $quantity, 2);
    }

    protected function resolveLineGrossAmount(mixed $line): float
    {
        return round(
            $this->resolveLineNetAmount($line) + $this->moneyToFloat(is_object($line) ? ($line->tax_total ?? 0) : 0),
            2,
        );
    }

    protected function buildFallbackCreditNoteLine(Model $order, Model $refund, float $amount): InvoiceLineData
    {
        $orderLines = Collection::make($order->lines ?? $order->lines()->get());
        $firstLine = $orderLines->first();
        [$variant, $product] = $this->resolveLineContext($firstLine);

        return new InvoiceLineData(
            description: $this->resolveRefundReference($order, $refund),
            quantity: 1.0,
            unitAmount: $amount,
            accountCode: $variant || $product
                ? $this->resolveAccountCode($variant, $product)
                : (string) ($this->settingsRepository->getDefaultAccountCode() ?? ''),
            taxType: $firstLine ? $this->resolveLineTaxType($firstLine) : 'OUTPUT2',
        );
    }

    /**
     * @param  array{id:string,number:?string,status:?string,allocations?:array<int, array{invoice_id:?string,amount:float}>}  $creditNote
     */
    protected function creditNoteAlreadyAllocatedToInvoice(array $creditNote, string $invoiceId): bool
    {
        return collect($creditNote['allocations'] ?? [])
            ->contains(fn (array $allocation): bool => (string) ($allocation['invoice_id'] ?? '') === $invoiceId);
    }

    /**
     * @param  array{id:string,number:?string,status:?string,payments?:array<int, array{reference:?string,amount:float,account_code:?string}>}  $creditNote
     */
    protected function creditNoteAlreadyPaidOut(array $creditNote, string $reference, float $amount, string $accountCode): bool
    {
        $normalizedReference = mb_strtolower(trim($reference));
        $normalizedAmount = round($amount, 2);

        return collect($creditNote['payments'] ?? [])
            ->contains(function (array $payment) use ($normalizedReference, $normalizedAmount, $accountCode): bool {
                return mb_strtolower(trim((string) ($payment['reference'] ?? ''))) === $normalizedReference
                    && round((float) ($payment['amount'] ?? 0), 2) === $normalizedAmount
                    && (string) ($payment['account_code'] ?? '') === $accountCode;
            });
    }
}
