<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Lunar\Extensions;

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Lunar\Admin\Support\Extending\EditPageExtension;

class EditCustomerPageExtension extends EditPageExtension
{
    public function headerActions(array $actions): array
    {
        return [
            ...$actions,
            Action::make('linkXeroContact')
                ->label('Link Xero contact')
                ->visible(fn ($livewire): bool => blank($livewire->record->xero_contact_id))
                ->form([
                    Placeholder::make('current_link')
                        ->label('Current Xero contact')
                        ->content(fn ($livewire): string => (string) ($livewire->record->xero_contact_id ?: 'Not linked')),
                    Select::make('contact_id')
                        ->label('Xero contact')
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search): array {
                            return app(XeroClientInterface::class)
                                ->searchContacts($search)
                                ->mapWithKeys(fn (array $contact): array => [
                                    $contact['id'] => trim(sprintf('%s (%s)', $contact['name'], $contact['email'] ?? 'no-email')),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(function (?string $value): ?string {
                            if (! filled($value)) {
                                return null;
                            }

                            $contact = app(XeroClientInterface::class)
                                ->searchContacts($value)
                                ->first(fn (array $contact): bool => $contact['id'] === $value);

                            if (! $contact) {
                                return $value;
                            }

                            return trim(sprintf('%s (%s)', $contact['name'], $contact['email'] ?? 'no-email'));
                        })
                        ->required(),
                ])
                ->action(function (array $data, $livewire): void {
                    /** @var Model $customer */
                    $customer = $livewire->record;
                    $customer->forceFill(['xero_contact_id' => $data['contact_id']])->save();

                    Notification::make()->title('Xero contact linked')->success()->send();
                }),
            Action::make('unlinkXeroContact')
                ->label('Unlink Xero contact')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn ($livewire): bool => filled($livewire->record->xero_contact_id))
                ->action(function ($livewire): void {
                    /** @var Model $customer */
                    $customer = $livewire->record;
                    $customer->forceFill(['xero_contact_id' => null])->save();

                    Notification::make()->title('Xero contact unlinked')->success()->send();
                }),
        ];
    }

    public function extendForm(Schema $schema): Schema
    {
        return $schema->components([
            ...$schema->getComponents(true),
            Section::make('Xero')
                ->schema([
                    Placeholder::make('xero_contact_status')
                        ->label('Xero contact')
                        ->content(fn ($livewire): string => (string) ($livewire->record->xero_contact_id ?: 'Not linked')),
                    Placeholder::make('xero_contact_link')
                        ->label('Xero link')
                        ->content(function ($livewire): HtmlString|string {
                            $contactId = $livewire->record->xero_contact_id;

                            if (! filled($contactId)) {
                                return 'No linked Xero contact';
                            }

                            $url = $this->xeroContactUrl((string) $contactId);

                            return new HtmlString('<a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="fi-link text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">Open in Xero</a>');
                        }),
                ])
                ->columnSpan(2),
        ]);
    }

    protected function xeroContactUrl(string $contactId): string
    {
        return 'https://go.xero.com/Contacts/View/'.$contactId;
    }
}
