<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Lunar\Extensions;

use CharlieLangridge\LunarXero\Enums\SyncOperation;
use CharlieLangridge\LunarXero\Enums\SyncStatus;
use CharlieLangridge\LunarXero\Jobs\SyncOrderInvoiceToXero;
use CharlieLangridge\LunarXero\Models\XeroSyncLog;
use CharlieLangridge\LunarXero\Support\XeroUrlFactory;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\ViewPageExtension;
use Throwable;

class ManageOrderPageExtension extends ViewPageExtension
{
    public function extendInfolistAsideSchema(array $schema): array
    {
        return [
            $this->xeroSection(),
            ...$schema,
        ];
    }

    protected function xeroSection(): Section
    {
        return Section::make('xero')
            ->heading('Xero')
            ->compact()
            ->headerActions([
                Action::make('syncXeroInvoice')
                    ->label('Sync invoice to Xero')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (Model $record): void {
                        try {
                            XeroSyncLog::query()->create([
                                'operation' => SyncOperation::Invoice->value,
                                'status' => SyncStatus::Pending->value,
                                'resource_type' => $record::class,
                                'resource_id' => $record->getKey(),
                                'payload' => ['order_id' => $record->getKey(), 'source' => 'admin_action'],
                                'attempt' => 0,
                                'started_at' => now(),
                            ]);

                            SyncOrderInvoiceToXero::dispatch($record->getKey());

                            Notification::make()
                                ->title('Xero invoice sync queued')
                                ->success()
                                ->send();
                        } catch (Throwable $throwable) {
                            Notification::make()
                                ->title('Failed to queue Xero invoice sync')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->schema([
                TextEntry::make('xero_invoice_status')
                    ->label('Invoice sync')
                    ->badge()
                    ->color(fn (Model $record): string => $this->syncStatusColor($record))
                    ->getStateUsing(fn (Model $record): string => $this->syncStatusLabel($record)),
                TextEntry::make('xero_invoice_id')
                    ->label('Xero invoice')
                    ->placeholder('Not synced yet')
                    ->weight(FontWeight::SemiBold)
                    ->getStateUsing(fn (Model $record): ?string => $record->xero_invoice_id)
                    ->url(fn (Model $record): ?string => XeroUrlFactory::invoiceUrl($record->xero_invoice_id))
                    ->openUrlInNewTab(),
                TextEntry::make('xero_invoice_error')
                    ->label('Last error')
                    ->placeholder('No recent sync errors')
                    ->getStateUsing(fn (Model $record): ?string => $this->latestInvoiceLog($record)?->error_message)
                    ->columnSpanFull(),
            ]);
    }

    protected function latestInvoiceLog(Model $record): ?XeroSyncLog
    {
        return XeroSyncLog::query()
            ->where('operation', SyncOperation::Invoice->value)
            ->where('resource_type', $record::class)
            ->where('resource_id', $record->getKey())
            ->latest('id')
            ->first();
    }

    protected function syncStatusLabel(Model $record): string
    {
        if (filled($record->xero_invoice_id)) {
            return 'Synced';
        }

        $log = $this->latestInvoiceLog($record);

        if (! $log) {
            return 'Not queued';
        }

        return match ((string) $log->status->value) {
            'processing' => 'Queued',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
            'succeeded' => 'Synced',
            default => 'Pending',
        };
    }

    protected function syncStatusColor(Model $record): string
    {
        if (filled($record->xero_invoice_id)) {
            return 'success';
        }

        $log = $this->latestInvoiceLog($record);

        if (! $log) {
            return 'gray';
        }

        return match ((string) $log->status->value) {
            'processing', 'pending' => 'warning',
            'failed' => 'danger',
            'succeeded' => 'success',
            default => 'gray',
        };
    }
}
