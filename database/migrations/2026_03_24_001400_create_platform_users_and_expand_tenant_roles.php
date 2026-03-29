<?php

use App\Enums\TenantRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('developer');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            $table->index(['is_active', 'role']);
        });

        DB::table('users')
            ->whereNotIn('role', TenantRole::values())
            ->update(['role' => TenantRole::STAFF->value]);

        Schema::table('users', function (Blueprint $table): void {
            $table->index(['tenant_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_tenant_id_role_index');
        });

        Schema::dropIfExists('platform_users');
    }
};

