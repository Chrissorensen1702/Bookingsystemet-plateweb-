<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || Schema::hasColumn('tenants', 'work_shifts_enabled')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('work_shifts_enabled')
                ->default(true)
                ->after('require_service_categories');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'work_shifts_enabled')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('work_shifts_enabled');
        });
    }
};

