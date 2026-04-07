# Lunar Xero

`lunar-xero` connects a [Lunar](https://lunarphp.io/) store to Xero. It adds a Xero settings page to the Lunar admin, stores the OAuth connection locally, and syncs contacts, invoices, captured payments, and refunds through queued jobs.

The package is built around Lunar's existing models and events, so it fits into a normal Lunar install instead of replacing the order flow.

## What it does

- Connects to Xero with OAuth 2.0 and PKCE
- Stores and manages the active Xero tenant
- Adds a Xero settings page in the Lunar admin
- Adds Xero account code and item code fields to products and variants
- Lets admins link or unlink customers to existing Xero contacts
- Syncs orders to Xero invoices
- Syncs captured payments to Xero payments
- Syncs refunds to Xero credit notes and payout allocations
- Logs each sync attempt so failures and skips are inspectable
- Falls back to billing or shipping address data when it needs to create a contact for a guest order

## Requirements

- PHP 8.4+
- Laravel 11 or 12
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
- customers can be linked to or unlinked from a Xero contact
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
7. stores the returned Xero invoice ID on the order
8. backfills payments and refunds where appropriate

If the order has a `customer_reference`, that value is used as the invoice reference and is also added as a zero-value purchase-order line.

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
