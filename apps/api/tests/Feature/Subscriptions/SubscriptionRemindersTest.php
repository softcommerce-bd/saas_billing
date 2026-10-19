<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionReminder;
use App\Modules\Subscriptions\Notifications\SubscriptionExpiryReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionRemindersTest extends TestCase
{
    private function setExpiry($tenant, string $firstReadOnlyDayLocal): void
    {
        Subscription::query()->where('tenant_id', $tenant->id)->update([
            'expires_at' => CarbonImmutable::parse($firstReadOnlyDayLocal.' 00:00', 'Asia/Dhaka')->utc(),
        ]);
    }

    public static function schedule(): array
    {
        // today (local), first read-only day, expected kind
        return [
            '7 days before' => ['2027-03-03', '2027-03-10', 'expiry_7d'],
            '3 days before' => ['2027-03-07', '2027-03-10', 'expiry_3d'],
            '1 day before (last valid day)' => ['2027-03-09', '2027-03-10', 'expiry_1d'],
            'on expiration' => ['2027-03-10', '2027-03-10', 'expired'],
            '3 days after' => ['2027-03-13', '2027-03-10', 'expired_3d'],
        ];
    }

    #[DataProvider('schedule')]
    public function test_reminder_sent_on_schedule(string $today, string $expiry, string $kind): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse($today.' 09:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        $this->setExpiry($tenant, $expiry);

        $this->artisan('subscriptions:send-reminders')->assertSuccessful();

        Notification::assertSentTo($owner, SubscriptionExpiryReminderNotification::class, fn ($n) => $n->kind === $kind);
        $this->assertSame(1, SubscriptionReminder::query()->where('kind', $kind)->where('status', 'sent')->count());
    }

    public function test_no_reminder_on_other_days(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2027-03-05 09:00', 'Asia/Dhaka'));
        ['tenant' => $tenant] = $this->createTenant('active');
        $this->setExpiry($tenant, '2027-03-10');

        $this->artisan('subscriptions:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_reminders_are_not_duplicated(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2027-03-03 09:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        $this->setExpiry($tenant, '2027-03-10');

        $this->artisan('subscriptions:send-reminders');
        $this->artisan('subscriptions:send-reminders');

        Notification::assertSentToTimes($owner, SubscriptionExpiryReminderNotification::class, 1);
    }

    public function test_renewal_re_arms_reminders_for_the_new_expiry(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2027-03-03 09:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        $this->setExpiry($tenant, '2027-03-10');
        $this->artisan('subscriptions:send-reminders');

        $this->setExpiry($tenant, '2027-04-10');
        $this->travelTo(CarbonImmutable::parse('2027-04-03 09:00', 'Asia/Dhaka'));
        $this->artisan('subscriptions:send-reminders');

        Notification::assertSentToTimes($owner, SubscriptionExpiryReminderNotification::class, 2);
    }

    public function test_suspended_tenants_get_no_reminders(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2027-03-03 09:00', 'Asia/Dhaka'));
        ['tenant' => $tenant] = $this->createTenant('suspended');
        $this->setExpiry($tenant, '2027-03-10');

        $this->artisan('subscriptions:send-reminders');

        Notification::assertNothingSent();
    }

    public function test_status_sync_marks_lapsed_subscriptions_expired(): void
    {
        ['tenant' => $tenant] = $this->createTenant('expired');

        $this->artisan('subscriptions:sync-statuses')->assertSuccessful();

        $this->assertSame('expired', Subscription::forTenant($tenant->id)->status->value);
    }
}
