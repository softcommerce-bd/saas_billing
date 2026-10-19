<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 12);
            $table->string('name', 160);
            $table->string('company_name', 160)->nullable();
            // Normalised to E.164 (+8801XXXXXXXXX) for Bangladesh mobile numbers.
            $table->string('mobile', 16)->nullable();
            $table->string('secondary_phone', 20)->nullable();
            $table->string('email', 254)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('district', 60)->nullable();
            $table->text('business_info')->nullable();
            $table->string('bin', 32)->nullable();
            $table->string('tin', 32)->nullable();
            // Receivable carried over from before the customer joined the system.
            // It is an input to the receivables ledger, not a running balance.
            $table->bigInteger('opening_balance_minor')->default(0);
            $table->bigInteger('credit_limit_minor')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('active');
            $table->string('preferred_language', 2)->default('bn');
            $table->boolean('sms_opt_in')->default(true);
            $table->boolean('email_opt_in')->default(true);
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'mobile']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_type_check CHECK (type IN ('individual','business'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_language_check CHECK (preferred_language IN ('bn','en'))");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_check CHECK (credit_limit_minor IS NULL OR credit_limit_minor >= 0)');

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('sku', 60)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('unit', 30);
            $table->bigInteger('default_price_minor');
            // Basis points: 1250 = 12.50 %.
            $table->unsignedInteger('default_discount_bp')->default(0);
            $table->unsignedInteger('tax_rate_bp')->nullable();
            $table->string('tax_label', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'type', 'is_active']);
            $table->index(['tenant_id', 'name']);
        });
        DB::statement("ALTER TABLE catalog_items ADD CONSTRAINT catalog_items_type_check CHECK (type IN ('product','service'))");
        DB::statement('ALTER TABLE catalog_items ADD CONSTRAINT catalog_items_price_check CHECK (default_price_minor >= 0)');
        DB::statement('ALTER TABLE catalog_items ADD CONSTRAINT catalog_items_discount_check CHECK (default_discount_bp <= 10000)');
        DB::statement('ALTER TABLE catalog_items ADD CONSTRAINT catalog_items_tax_check CHECK (tax_rate_bp IS NULL OR tax_rate_bp <= 10000)');
        DB::statement('CREATE UNIQUE INDEX catalog_items_tenant_sku_unique ON catalog_items (tenant_id, lower(sku)) WHERE sku IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('customers');
    }
};
