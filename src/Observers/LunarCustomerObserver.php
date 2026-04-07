<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Observers;

use CharlieLangridge\LunarXero\Jobs\SyncCustomerContactToXero;
use Illuminate\Database\Eloquent\Model;

class LunarCustomerObserver
{
    public function created(Model $customer): void
    {
        $this->queueContactSync($customer);
    }

    public function updated(Model $customer): void
    {
        if ($customer->wasChanged('xero_contact_id') || filled($customer->xero_contact_id)) {
            return;
        }

        $this->queueContactSync($customer);
    }

    protected function queueContactSync(Model $customer): void
    {
        if (filled($customer->xero_contact_id)) {
            return;
        }

        SyncCustomerContactToXero::dispatch($customer->getKey());
    }
}
