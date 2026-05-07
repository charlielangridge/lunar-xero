# Lunar Xero

`lunar-xero` connects a [Lunar](https://lunarphp.io/) store to Xero. It adds a Xero settings page to the Lunar admin, stores the OAuth connection locally, and syncs contacts, invoices, captured payments, and refunds through queued jobs.

The package is built around Lunar's existing models and events, so it fits into a normal Lunar install instead of replacing the order flow.

## What it does

- Connects to Xero with OAuth 2.0 and PKCE
- Stores and manages the active Xero tenant
- Adds a Xero settings page in the Lunar admin
- Adds Xero account code and item code fields to products and variants
- Lets admins link or unlink customers to existing Xero contacts
- Lets admins opt individual customers into including Lunar order line notes on Xero invoice lines
- Syncs orders to Xero invoices
- Syncs captured payments to Xero payments
- Syncs refunds to Xero credit notes and payout allocations
- Logs each sync attempt so failures and skips are inspectable
- Falls back to billing or shipping address data when it needs to create a contact for a guest order

## Requirements

- PHP 8.4+
- Laravel 11, 12 or 13
- Filament 4
- Lunar 1.x
- A running queue worker
- A Xero app with OAuth enabled

## Installation

Install the package:

```bash
composer require charlielangridge/lunar-xero
```

Publish the config file if you want to override the defaults:

```bash
php artisan vendor:publish --tag=lunarpanel-xero-config
```

Publish the views only if you need to customise them:

```bash
php artisan vendor:publish --tag=lunarpanel-xero-views
```

Run your migrations:

```bash
php artisan migrate
```

The service provider is auto-discovered. In a standard Lunar panel setup the plugin page is registered automatically as well.

## Configuration

The package config lives at `config/lunarpanel-xero.php`.

The main settings are:

- `defaults.invoice_status`: default invoice status sent to Xero, either `DRAFT` or `AUTHORISED`
- `defaults.sync_queue`: queue name used for contact, invoice, and payment jobs
- `oauth.read_only`: blocks write calls to Xero when enabled
- `events.order_created`: event class used to dispatch invoice syncs
- `events.payment_completed`: event class used to dispatch payment syncs
- `models.*`: model classes the package should use
- `tables.*`: table names for the package tables and patched Lunar tables
- `routes.prefix` and `routes.middleware`: override the OAuth route group when needed
- `charity.*`: configures where charity VAT relief metadata is read from on the order for Xero invoice traceability lines

### Environment

Add these values to `.env`:

```dotenv
XERO_CLIENT_ID=
XERO_CLIENT_SECRET=
XERO_REDIRECT_URI=
XERO_READ_ONLY=false
LUNARPANEL_XERO_ROUTE_PREFIX=
```

If `XERO_REDIRECT_URI` is left empty, the package falls back to the generated `lunarpanel-xero.callback` route.

## Routes

The package registers these routes:

- `GET /connect`
- `GET /callback`
- `POST /disconnect`
- `POST /tenants/refresh`
- `POST /tenants/select`

By default they sit under the Lunar panel path with `/xero` appended. If the panel path cannot be resolved, the fallback prefix is `lunar/xero`.

## Admin areas

The Xero settings page lets you:

- start or disconnect the OAuth connection
- refresh tenants and choose the active tenant
- choose whether invoices are created as draft or authorised
- set a default revenue account code
- map Lunar payment types to Xero account codes

The package also extends existing Lunar admin screens:

- products get a dedicated Xero page
- variants get a dedicated Xero page
- customers can be linked to or unlinked from a Xero contact, and can opt into Xero invoice line notes
- orders show the current invoice sync state, Xero invoice ID, last error, and a manual sync action

## Sync behaviour

### Contacts

Customer sync is observer-driven.

- New customers queue a contact sync job
- Unlinked customers queue another sync when they are updated
- Existing `xero_contact_id` values are respected
- Matching Xero contacts can be found by email before a new contact is created
- Guest orders can create a contact from billing details, with shipping used as a fallback

### Invoices

Invoice sync runs from the configured order-created event and can also be queued manually from the order page.

During invoice sync the package:

1. creates a sync log entry
2. resolves or creates a Xero contact
3. builds invoice lines from the order
4. resolves account codes from variant, product, then package default settings
5. resolves or creates Xero item codes where possible
6. creates or updates the Xero invoice
7. stores the returned Xero invoice ID, invoice number, invoice status, and customer online invoice URL on the order
8. backfills payments and refunds where appropriate

Synced orders can contain these Xero invoice fields:

- `xero_invoice_id`: the Xero invoice UUID
- `xero_invoice_number`: the human-readable Xero invoice number, such as `INV-1234`
- `xero_invoice_status`: the latest status returned by Xero, such as `DRAFT`, `AUTHORISED`, or `PAID`
- `xero_online_invoice_url`: Xero's customer-safe online invoice URL, fetched only for customer-visible statuses

If the order has a `customer_reference`, that value is used as the invoice reference and is also added as a zero-value purchase-order line.

Customers can be opted into order line notes from the Lunar customer edit page with the `Include order line notes on Xero invoices` toggle. The underlying `xero_include_order_line_notes` customer field defaults to `false`. When it is enabled and an order line has non-blank `notes`, the package appends the notes underneath the existing Xero invoice line description:

```text
Existing description
Notes: Order line notes
```

If the order contains charity VAT relief metadata, the package also appends zero-value traceability lines for the charity name, charity number, declaration name, and declared-at timestamp. By default these values are read from `order.meta.charity_vat_relief.*`, matching `ganda-webstore`, and the taxable product lines still keep their VAT mapping from Lunar tax data.

You can override those defaults in `config/lunarpanel-xero.php`:

```php
'charity' => [
    'enabled' => true,
    'name_path' => 'meta.charity_vat_relief.charity_name',
    'number_path' => 'meta.charity_vat_relief.charity_number',
    'declaration_name_path' => 'meta.charity_vat_relief.declaration_name',
    'declared_at_path' => 'meta.charity_vat_relief.declared_at',
],
```

### Customer invoice links

For storefront customer order pages, use the stored `xero_online_invoice_url` after your app has already checked that the signed-in customer owns the order. Do not expose the internal `go.xero.com` invoice URL used by the Lunar admin panel; that link is intended for staff with Xero access.

Draft invoices are not customer-visible by default. The helper only returns a URL for `AUTHORISED` and `PAID` invoices:

```php
use CharlieLangridge\LunarXero\Support\XeroUrlFactory;

$invoiceUrl = XeroUrlFactory::customerInvoiceUrl(
    $order->xero_online_invoice_url,
    $order->xero_invoice_status,
);
```

A `ganda-webstore` style order show view can then render the link conditionally:

```blade
@php
    $xeroInvoiceUrl = \CharlieLangridge\LunarXero\Support\XeroUrlFactory::customerInvoiceUrl(
        $order->xero_online_invoice_url,
        $order->xero_invoice_status,
    );
@endphp

@if ($xeroInvoiceUrl)
    <a href="{{ $xeroInvoiceUrl }}" target="_blank" rel="noopener">
        View Xero invoice
    </a>
@endif
```

For an Inertia and Vue storefront, resolve the customer-safe URL in your Laravel controller or page response and pass only that final value to the component:

```php
use CharlieLangridge\LunarXero\Support\XeroUrlFactory;
use Inertia\Inertia;

return Inertia::render('Account/Orders/Show', [
    'order' => [
        'id' => $order->id,
        'reference' => $order->reference,
        'xeroInvoiceUrl' => XeroUrlFactory::customerInvoiceUrl(
            $order->xero_online_invoice_url,
            $order->xero_invoice_status,
        ),
    ],
]);
```

Then render it in the Vue page only when the prop is present:

```vue
<script setup>
defineProps({
    order: {
        type: Object,
        required: true,
    },
})
</script>

<template>
    <a
        v-if="order.xeroInvoiceUrl"
        :href="order.xeroInvoiceUrl"
        target="_blank"
        rel="noopener"
    >
        View Xero invoice
    </a>
</template>
```

### Payments and refunds

Payment sync runs from the configured payment event and from the transaction observer.

- Captured payments are posted against the matching Xero invoice
- Refund transactions are turned into Xero credit notes
- Refund credit notes are allocated back to the invoice and can be paid out through the mapped account
- Duplicate payments and refunds are checked before new records are created

## Queueing

All sync jobs implement `ShouldQueue` and default to the `xero` queue unless you override `defaults.sync_queue`.

Example worker command:

```bash
php artisan queue:work --queue=xero,default
```

## Logging

Each sync attempt writes to `xero_sync_logs`, including:

- operation type
- resource type and ID
- payload snapshot
- external reference
- status
- response payload
- error message and exception class when something fails

That table is the first place to look when a sync has been skipped or has failed.

## Development

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Run formatting:

```bash
composer format
```

## License

MIT. See [LICENSE.md](LICENSE.md).
