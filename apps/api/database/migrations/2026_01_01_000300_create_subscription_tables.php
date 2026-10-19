<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * SaaS subscription schema. These tables record what tenants pay the PLATFORM.
 * They are deliberately separate from the tenant's own customer invoices/payments.
 * Money columns are integer minor units (poisha for BDT).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Feature entitlements and quotas, independent of the billing duration.
        Schema::create('entitlement_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_invoices_per_month')->nullable();
            $table->unsignedInteger('sms_monthly_allowance')->default(0);
            $table->unsignedInteger('storage_mb')->default(0);
            $table->jsonb('modules');
            $table->timestamps();
        });

        // A plan is a billing duration ("package") with an admin-configurable price.
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('duration_months');
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('BDT');
            $table->foreignUlid('entitlement_profile_id')->constrained('entitlement_profiles');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_code_check CHECK (code IN ('monthly','quarterly','half_yearly','yearly'))");
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_duration_check CHECK (duration_months IN (1,3,6,12))');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_price_check CHECK (price_minor >= 0)');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Exactly one subscription record per tenant; its history lives in subscription_periods.
            $table->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('plan_id')->nullable()->constrained('plans');
            $table->string('status', 20);
            $table->boolean('is_trial')->default(false);
            $table->timestamp('starts_at')->nullable();
            // EXCLUSIVE upper bound (UTC). Always aligned to local midnight in Asia/Dhaka.
            $table->timestamp('expires_at')->nullable();
            // Day-of-month the period chain is anchored to (prevents end-of-month drift).
            $table->unsignedSmallInteger('anchor_day')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->ulid('payment_id')->nullable();
            $table->string('verified_transaction_id', 64)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('renewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('pending_payment','active','expired','cancelled','suspended'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_anchor_check CHECK (anchor_day IS NULL OR anchor_day BETWEEN 1 AND 31)');

        Schema::create('subscription_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained('plans');
            // Snapshot of the plan at order time: later price edits never change an order.
            $table->string('plan_code', 20);
            $table->unsignedSmallInteger('duration_months');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->string('merchant_invoice_number', 40)->unique();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE subscription_orders ADD CONSTRAINT subscription_orders_status_check CHECK (status IN ('pending','paid','failed','cancelled','expired'))");
        DB::statement('ALTER TABLE subscription_orders ADD CONSTRAINT subscription_orders_amount_check CHECK (amount_minor > 0)');

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('order_id');
            $table->string('gateway', 20);
            $table->string('environment', 12);
            $table->string('gateway_payment_id', 64)->nullable();
            $table->string('status', 20);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // Set only from a server-side gateway verification response.
            $table->string('verified_trx_id', 64)->nullable();
            $table->bigInteger('verified_amount_minor')->nullable();
            $table->timestamp('verified_at')->nullable();
            // Set once the verified payment has extended the subscription (idempotency marker).
            $table->timestamp('applied_at')->nullable();
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_note', 1000)->nullable();
            $table->boolean('requires_review')->default(false);
            $table->string('failure_code', 40)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedSmallInteger('check_attempts')->default(0);
            // Sanitised gateway responses (no credentials or tokens).
            $table->jsonb('gateway_payload')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('subscription_orders')->cascadeOnDelete();
            $table->unique(['gateway', 'gateway_payment_id']);
            $table->unique(['gateway', 'verified_trx_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['status', 'last_checked_at']);
        });
        DB::statement("ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_status_check CHECK (status IN ('initiated','completed','failed','cancelled','expired','unknown','refunded','partially_refunded'))");
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_refund_check CHECK (refunded_amount_minor >= 0 AND refunded_amount_minor <= amount_minor)');
        // At most one verified payment per order: a second successful charge can never be applied twice.
        DB::statement('CREATE UNIQUE INDEX subscription_payments_one_verified_per_order ON subscription_payments (order_id) WHERE verified_trx_id IS NOT NULL');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('subscription_payments')->nullOnDelete();
        });

        // Ledger of every validity extension: verified payments, trials and complimentary grants.
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subscription_id');
            $table->string('source', 16);
            $table->ulid('payment_id')->nullable()->unique();
            $table->foreignUlid('plan_id')->nullable()->constrained('plans');
            $table->unsignedSmallInteger('months')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->foreignUlid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'subscription_id'])->references(['tenant_id', 'id'])->on('subscriptions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('subscription_payments');
            $table->index(['subscription_id', 'period_end']);
        });
        DB::statement("ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_source_check CHECK (source IN ('payment','trial','complimentary'))");
        DB::statement("ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_payment_source_check CHECK ((source = 'payment') = (payment_id IS NOT NULL))");
        DB::statement("ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_complimentary_reason_check CHECK (source <> 'complimentary' OR (reason IS NOT NULL AND granted_by_user_id IS NOT NULL))");
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_range_check CHECK (period_end > period_start)');

        // Time-limited, reasoned, audited platform override of read-only mode.
        Schema::create('entitlement_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('granted_by_user_id')->constrained('users');
            $table->string('reason', 500);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });
        DB::statement('ALTER TABLE entitlement_overrides ADD CONSTRAINT entitlement_overrides_range_check CHECK (expires_at > starts_at)');

        // De-duplication ledger for subscription expiry reminders.
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('kind', 24);
            // The expiry the reminder refers to; a renewal changes expires_at, so reminders re-arm.
            $table->timestamp('expires_at_ref');
            $table->string('channel', 12);
            $table->string('status', 12);
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'kind', 'expires_at_ref', 'channel'], 'subscription_reminders_dedupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
        Schema::dropIfExists('entitlement_overrides');
        Schema::dropIfExists('subscription_periods');
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropForeign(['payment_id']));
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_orders');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('entitlement_profiles');
    }
};
