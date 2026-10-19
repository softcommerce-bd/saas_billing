<?php

namespace Tests\Concurrency;

use App\Modules\Subscriptions\Enums\OrderStatus;
use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionOrder;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPeriod;
use App\Modules\Subscriptions\Services\SubscriptionActivationService;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;

/**
 * Real multi-process race: forked workers apply payments at the same instant against
 * PostgreSQL with committed data (no wrapping test transaction). Verifies that row
 * locks, the applied_at marker and the unique period/payment index prevent double
 * extension.
 */
class ConcurrentActivationTest extends BaseTestCase
{
    use InteractsWithTenants;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required.');
        }

        Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => PlatformSeeder::class]);
        Notification::fake();
        $this->dir = sys_get_temp_dir().'/smartbill-race-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        // Leave a clean database for the transactional test suites.
        Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => PlatformSeeder::class]);
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function verifiedPayment(Tenant $tenant, string $suffix): SubscriptionPayment
    {
        $plan = Plan::query()->where('code', 'monthly')->first();

        return $this->inTenant($tenant, function () use ($plan, $suffix) {
            $order = SubscriptionOrder::create([
                'plan_id' => $plan->id, 'plan_code' => 'monthly', 'duration_months' => 1, 'amount_minor' => $plan->price_minor,
                'currency' => 'BDT', 'status' => OrderStatus::Pending, 'merchant_invoice_number' => 'SUB-RACE-'.$suffix,
            ]);

            return SubscriptionPayment::create([
                'order_id' => $order->id, 'gateway' => 'bkash', 'environment' => 'sandbox', 'gateway_payment_id' => 'RACE'.$suffix,
                'status' => PaymentStatus::Completed, 'amount_minor' => $plan->price_minor, 'currency' => 'BDT',
                'verified_trx_id' => 'TRXRACE'.$suffix, 'verified_amount_minor' => $plan->price_minor, 'verified_at' => now(),
            ]);
        });
    }

    /**
     * @param  list<string>  $paymentIds  one worker per entry
     * @return list<string> worker results
     */
    private function race(array $paymentIds): array
    {
        DB::disconnect();
        $go = $this->dir.'/go';
        $pids = [];

        foreach ($paymentIds as $i => $paymentId) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $result = 'error';
                try {
                    while (! file_exists($go)) {
                        usleep(500);
                    }
                    $result = app(SubscriptionActivationService::class)->applyVerifiedPayment($paymentId) ? 'applied' : 'noop';
                } catch (\Throwable $e) {
                    $result = 'error: '.$e->getMessage();
                }
                file_put_contents($this->dir."/result-{$i}", $result);
                // Skip PHP/PHPUnit shutdown in the child; it shares the parent's resources.
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        usleep(200_000);
        touch($go);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return array_map(fn ($i) => (string) @file_get_contents($this->dir."/result-{$i}"), array_keys($paymentIds));
    }

    public function test_same_payment_applied_concurrently_extends_exactly_once(): void
    {
        ['tenant' => $tenant] = $this->createTenant('expired');
        $payment = $this->verifiedPayment($tenant, 'A');

        $results = $this->race(array_fill(0, 4, $payment->id));

        sort($results);
        $this->assertSame(['applied', 'noop', 'noop', 'noop'], $results);
        $this->assertSame(1, SubscriptionPeriod::query()->count());
        $this->assertNotNull(SubscriptionPayment::acrossTenants()->find($payment->id)->applied_at);
    }

    public function test_different_payments_applied_concurrently_chain_without_overlap(): void
    {
        ['tenant' => $tenant] = $this->createTenant('expired');
        $a = $this->verifiedPayment($tenant, 'A');
        $b = $this->verifiedPayment($tenant, 'B');

        $results = $this->race([$a->id, $b->id]);

        $this->assertSame(['applied', 'applied'], $results);
        $periods = SubscriptionPeriod::query()->orderBy('period_start')->get();
        $this->assertCount(2, $periods);
        $this->assertTrue($periods[1]->period_start->equalTo($periods[0]->period_end));
        $this->assertTrue(Subscription::forTenant($tenant->id)->expires_at->equalTo($periods[1]->period_end));
    }
}
