<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Jobs;

use CharlieLangridge\LunarXero\Services\XeroSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCustomerContactToXero implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int|string $customerId,
    ) {
        $this->onQueue(config('lunarpanel-xero.defaults.sync_queue', 'default'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(XeroSyncService $syncService): void
    {
        $syncService->syncCustomerContactById($this->customerId);
    }
}
