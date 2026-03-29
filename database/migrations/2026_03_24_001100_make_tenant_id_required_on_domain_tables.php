<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (['users', 'customers', 'services', 'bookings'] as $tableName) {
            DB::statement("ALTER TABLE `{$tableName}` MODIFY `tenant_id` BIGINT UNSIGNED NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (['bookings', 'services', 'customers', 'users'] as $tableName) {
            DB::statement("ALTER TABLE `{$tableName}` MODIFY `tenant_id` BIGINT UNSIGNED NULL");
        }
    }
};
