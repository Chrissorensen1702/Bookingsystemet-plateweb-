<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('services')
            ->where(function ($query): void {
                $query->whereNull('category_name')
                    ->orWhereRaw("TRIM(category_name) = ''");
            })
            ->update([
                'category_name' => 'Standard',
                'updated_at' => now(),
            ]);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `services` MODIFY `category_name` VARCHAR(120) NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `services` MODIFY `category_name` VARCHAR(120) NULL');
    }
};
