<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->dropUnique('user_work_shifts_unique_user_day');
            });
        } catch (\Throwable) {
            // Ignore if unique index already removed.
        }

        try {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'user_id', 'shift_date'],
                    'user_work_shifts_tenant_user_date_idx'
                );
            });
        } catch (\Throwable) {
            // Ignore if index already exists.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->dropIndex('user_work_shifts_tenant_user_date_idx');
            });
        } catch (\Throwable) {
            // Ignore if index does not exist.
        }

        Schema::table('user_work_shifts', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'location_id', 'user_id', 'shift_date'],
                'user_work_shifts_unique_user_day'
            );
        });
    }
};

