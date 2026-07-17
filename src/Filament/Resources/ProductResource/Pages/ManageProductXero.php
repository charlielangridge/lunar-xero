<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Filament\Resources\ProductResource\Pages;

use BackedEnum;
use CharlieLangridge\LunarXero\Support\XeroAccountOptions;
use CharlieLangridge\LunarXero\Support\XeroItemCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Support\Pages\BaseEditRecord;

class ManageProductXero extends BaseEditRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Xero';
    }

    public static function getNavigationLabel(): string
    {
        return 'Xero';
    }

    public static function getNavigationIcon(): string|BackedEnum|HtmlString|null
    {
        return new HtmlString(view('lunarpanel-xero::filament.partials.xero-nav-icon')->render());
    }

    public function getBreadcrumb(): string
    {
        return 'Xero';
    }

    protected function getDefaultHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill([
            'xero_account_code' => $data['xero_account_code'] ?? null,
            'xero_item_code' => $data['xero_item_code'] ?? null,
        ])->save();

        return $record->refresh();
    }

    public function form(Schema $schema): Schema
    {
        $accounts = app(XeroAccountOptions::class);

        return $schema->components([
            Section::make('Xero')
                ->schema([
                    Select::make('xero_account_code')
                        ->label('Xero account')
                        ->searchable()
                        ->options($accounts->invoiceOptions())
                        ->getSearchResultsUsing(fn (string $search): array => $accounts->searchInvoiceOptions($search))
                        ->getOptionLabelUsing(fn (?string $value): ?string => $accounts->invoiceLabel($value)),
                    TextInput::make('xero_item_code')
                        ->label('Xero item code')
                        ->maxLength(XeroItemCode::MaxLength),
                ])
                ->columns([
                    'sm' => 1,
                    'xl' => 2,
                ]),
        ]);
    }

    public function getRelationManagers(): array
    {
        return [];
    }
}
