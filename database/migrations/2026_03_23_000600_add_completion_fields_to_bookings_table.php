<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('completed_by_user_id')
                ->nullable()
                ->after('staff_member_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('status');

            $table->index('completed_at');
        });

        DB::table('bookings')
            ->whereNotIn('status', ['confirmed', 'completed', 'canceled'])
            ->update(['status' => 'confirmed']);

        DB::table('bookings')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('ends_at')]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['completed_at']);
            $table->dropForeign(['completed_by_user_id']);
            $table->dropColumn(['completed_by_user_id', 'completed_at']);
        });
    }
};
