<?php

namespace App\Modules\Subscriptions\Console;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionReminder;
use App\Modules\Subscriptions\Notifications\SubscriptionExpiryReminderNotification;
use App\Modules\Subscriptions\Services\CalendarPeriod;
use App\Modules\Tenancy\Enums\Role;
use App\Support\Time\BusinessClock;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends renewal reminders to company owners on the configured days relative to the
 * local valid-through date. The (subscription, kind, expiry, channel) unique key makes
 * every reminder at-most-once; a renewal changes expires_at, which re-arms reminders.
 * Runs regardless of read-only mode: renewal notices are platform operations.
 */
class SendSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:send-reminders';

    protected $description = 'Send subscription expiry reminders to company owners';

    public function handle(): int
    {
        $calendar = new CalendarPeriod(BusinessClock::timezone());
        $today = $calendar->localDate(BusinessClock::now());
        $sent = 0;

        foreach (config('subscriptions.reminders') as $kind => $offsetDays) {
            // Reminder fires when today == (first day without access) + offset.
            // expired: on the first read-only day; expiry_1d: last valid day, etc.
            $boundaryLocal = $today->subDays($offsetDays);
            $from = $boundaryLocal->utc();
            $until = $boundaryLocal->addDay()->utc();

            $subscriptions = Subscription::query()
                ->with('tenant')
                ->where('expires_at', '>=', $from)
                ->where('expires_at', '<', $until)
                ->whereNotIn('status', [SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled])
                ->get();

            foreach ($subscriptions as $subscription) {
                if ($subscription->tenant === null || $subscription->tenant->isSuspended() || $subscription->tenant->isClosed()) {
                    continue;
                }
                $sent += $this->remind($subscription, $kind, $calendar);
            }
        }

        $this->info("Sent {$sent} subscription reminders.");

        return self::SUCCESS;
    }

    private function remind(Subscription $subscription, string $kind, CalendarPeriod $calendar): int
    {
        try {
            $reminder = DB::transaction(fn () => SubscriptionReminder::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'kind' => $kind,
                'expires_at_ref' => $subscription->expires_at,
                'channel' => 'mail',
                'status' => 'pending',
            ]));
        } catch (UniqueConstraintViolationException) {
            return 0;
        }

        $owners = $subscription->tenant->memberships()
            ->where('status', 'active')
            ->whereHas('roleRows', fn ($q) => $q->where('role', Role::Owner->value))
            ->with('user')
            ->get()
            ->pluck('user');

        try {
            foreach ($owners as $owner) {
                $owner->notify(new SubscriptionExpiryReminderNotification(
                    $kind,
                    $subscription->tenant->name,
                    $calendar->validThrough($subscription->expires_at),
                ));
            }
            $reminder->update(['status' => 'sent']);

            return 1;
        } catch (\Throwable $e) {
            Log::error('Subscription reminder failed.', ['subscription_id' => $subscription->id, 'kind' => $kind, 'error' => $e->getMessage()]);
            $reminder->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)]);

            return 0;
        }
    }
}
