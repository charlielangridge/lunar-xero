@php
    $connected = filled($token?->access_token) && filled($tenant?->tenant_id);
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 shadow-sm">
    <div class="font-medium text-gray-950">
        {{ $connected ? 'Connected to Xero' : 'Not connected to Xero' }}
    </div>

    <div class="mt-2 space-y-1">
        <div>Tenant: {{ $tenant?->tenant_name ?? 'None selected' }}</div>
        <div>Token expiry: {{ $token?->expires_at?->toDateTimeString() ?? 'Not available' }}</div>
        <div>Last invoice sync: {{ data_get($settings->connection_meta, 'last_invoice_sync_at', 'Never') }}</div>
        <div>Last payment sync: {{ data_get($settings->connection_meta, 'last_payment_sync_at', 'Never') }}</div>
    </div>
</div>
