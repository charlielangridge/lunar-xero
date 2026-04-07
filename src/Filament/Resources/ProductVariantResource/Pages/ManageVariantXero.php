<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Filament\Resources\ProductVariantResource\Pages;

use BackedEnum;
use CharlieLangridge\LunarXero\Support\XeroAccountOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Filament\Resources\ProductVariantResource;
use Lunar\Admin\Support\Pages\BaseEditRecord;

class ManageVariantXero extends BaseEditRecord
{
    protected static string $resource = ProductVariantResource::class;

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

    protected function getDefaultHeaderActions(): array
    {
        return [
            ProductVariantResource::getVariantSwitcherWidget(
                $this->getRecord()
            ),
        ];
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->url(function (Model $record) {
            return ProductResource::getUrl('variants', [
                'record' => $record->product,
            ]);
        });
    }

    public function getBreadcrumbs(): array
    {
        return [
            ...ProductVariantResource::getBaseBreadcrumbs(
                $this->getRecord()
            ),
            ProductVariantResource::getUrl('xero', [
                'record' => $this->getRecord(),
            ]) => $this->getTitle(),
        ];
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
                        ->maxLength(255),
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
