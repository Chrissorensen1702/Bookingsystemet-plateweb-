<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_work_shifts')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('user_work_shifts', 'is_public')) {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->boolean('is_public')->default(false);
            });
        }

        if (! Schema::hasColumn('user_work_shifts', 'published_at')) {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->timestamp('published_at')->nullable();
            });
        }

        if (! Schema::hasColumn('user_work_shifts', 'published_by_user_id')) {
            Schema::table('user_work_shifts', function (Blueprint $table) use ($driver): void {
                if ($driver === 'sqlite') {
                    $table->unsignedBigInteger('published_by_user_id')->nullable();

                    return;
                }

                $table->foreignId('published_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        Schema::table('user_work_shifts', function (Blueprint $table): void {
            $table->index(
                ['tenant_id', 'location_id', 'shift_date', 'is_public'],
                'user_work_shifts_public_week_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_work_shifts')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        try {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->dropIndex('user_work_shifts_public_week_idx');
            });
        } catch (\Throwable) {
            // Ignore if index is already removed.
        }

        if (Schema::hasColumn('user_work_shifts', 'published_by_user_id')) {
            if ($driver !== 'sqlite') {
                try {
                    Schema::table('user_work_shifts', function (Blueprint $table): void {
                        $table->dropForeign(['published_by_user_id']);
                    });
                } catch (\Throwable) {
                    // Ignore if foreign key is already removed.
                }
            }

            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->dropColumn('published_by_user_id');
            });
        }

        if (Schema::hasColumn('user_work_shifts', 'published_at')) {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->dropColumn('published_at');
            });
        }

        if (Schema::hasColumn('user_work_shifts', 'is_public')) {
            Schema::table('user_work_shifts', function (Blueprint $table): void {
                $table->dropColumn('is_public');
            });
        }
    }
};

