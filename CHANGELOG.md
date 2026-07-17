# Changelog

All notable changes to `charlielangridge/lunar-xero` will be documented in this file.

## v0.5.2 - 2026-07-17

- Added Xero-safe item code generation for long Lunar variant SKUs.
- Added product and variant hooks plus a backfill command for Xero item codes.
- Added validation so explicit Xero item codes respect Xero's 30 character limit before sync.

## v0.5.0 - 2026-05-08

- Added support for syncing and emailing authorised Xero invoices.
- Added Xero invoice `sent_to_contact` checks to avoid duplicate customer invoice emails.
