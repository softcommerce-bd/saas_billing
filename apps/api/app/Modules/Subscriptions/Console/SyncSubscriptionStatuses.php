<?php

namespace App\Modules\Subscriptions\Console;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Time\BusinessClock;
use Illuminate\Console\Command;

/**
 * Keeps the stored status column in line with expires_at for listings and reports.
 * NOT a security control: access is computed live by SubscriptionEntitlementService.
 */
class SyncSubscriptionStatuses extends Command
{
    protected $signature = 'subscriptions:sync-statuses';

    protected $description = 'Mark lapsed subscriptions as expired (informational status only)';

    public function handle(): int
    {
        $count = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('expires_at', '<=', BusinessClock::now())
            ->update(['status' => SubscriptionStatus::Expired, 'is_trial' => false, 'updated_at' => now()]);

        $this->info("Marked {$count} subscriptions as expired.");

        return self::SUCCESS;
    }
}
