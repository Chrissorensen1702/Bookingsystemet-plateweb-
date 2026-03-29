<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('is_bookable');
                $table->index(['tenant_id', 'is_active'], 'users_tenant_id_is_active_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex('users_tenant_id_is_active_index');
                $table->dropColumn('is_active');
            });
        }
    }
};
