<x-filament-panels::page>
    @if (! $isConnected)
        <div class="flex items-start justify-start py-8">
            <x-lunarpanel-xero::xero-brand-button
                :href="route('lunarpanel-xero.connect')"
                blue="lunarpanel-xero::filament.partials.xero-buttons.connect-blue"
                white="lunarpanel-xero::filament.partials.xero-buttons.connect-white"
                title="Connect to Xero"
            />
        </div>
    @else
        <div class="space-y-6">
            @include('lunarpanel-xero::filament.partials.connection-summary', [
                'settings' => $settings,
                'tenant' => $tenant,
                'token' => $token,
            ])

            <div class="flex flex-wrap gap-3">
                <x-filament::button
                    color="gray"
                    wire:click="refreshTenants"
                >
                    Refresh tenants
                </x-filament::button>

                <x-lunarpanel-xero::xero-brand-button
                    wire-click="disconnect"
                    wire-target="disconnect"
                    blue="lunarpanel-xero::filament.partials.xero-buttons.disconnect-blue"
                    white="lunarpanel-xero::filament.partials.xero-buttons.disconnect-white"
                    title="Disconnect from Xero"
                />
            </div>

            <form wire:submit="save" class="space-y-6">
                <div class="grid gap-6 md:grid-cols-2">
                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Active tenant</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="activeTenantId">
                                <option value="">Select a tenant</option>
                                @foreach ($tenants as $tenantOption)
                                    <option value="{{ $tenantOption->tenant_id }}">{{ $tenantOption->tenant_name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Invoice status</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="invoiceStatus">
                                @foreach ($invoiceStatuses as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>

                    <label class="block md:col-span-2">
                        <span class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Default income account</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="defaultAccountCode">
                                <option value="">Select an account</option>
                                @foreach ($accountOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                </div>

                <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-950">Payment type mappings</h3>
                    </div>

                    @if (! $hasPaymentTypes)
                        <p class="text-sm text-gray-500">
                            No Lunar payment types are configured for this install.
                        </p>
                    @endif

                    @foreach ($paymentMappings as $index => $mapping)
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Payment type</span>
                                <x-filament::input.wrapper disabled>
                                    <div class="block w-full px-3 py-2 text-sm text-gray-950 dark:text-white">
                                        {{ $mapping['payment_label'] ?? $mapping['payment_type'] }}
                                    </div>
                                </x-filament::input.wrapper>
                            </label>

                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Xero payment account</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="paymentMappings.{{ $index }}.account_code">
                                        <option value="">Select a payment account</option>

                                        @foreach ($paymentAccountOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                        </div>
                    @endforeach
                </div>

                <x-filament::button type="submit" wire:target="save">
                    Save settings
                </x-filament::button>
            </form>
        </div>
    @endif
</x-filament-panels::page>
