<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('district', 60)->nullable();
            $table->string('bin', 32)->nullable();
            $table->string('tin', 32)->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->string('locale', 5)->default('en');
            $table->string('invoice_prefix', 12)->default('INV-');
            $table->timestamp('onboarding_completed_at')->nullable();
            // Suspension is a platform decision, independent of subscription validity.
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamp('closure_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_status_check CHECK (status IN ('active','disabled'))");

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['membership_id', 'role']);
        });
        DB::statement("ALTER TABLE membership_roles ADD CONSTRAINT membership_roles_role_check CHECK (role IN ('owner','admin','accounts_manager','billing_executive','collection_executive','viewer'))");

        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email', 254);
            $table->jsonb('roles');
            // Only a SHA-256 hash of the invitation token is stored.
            $table->char('token_hash', 64)->unique();
            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
    }
};
