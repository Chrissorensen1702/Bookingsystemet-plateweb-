<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('is_online_bookable')
                ->default(true)
                ->after('description');
            $table->string('category_name', 120)
                ->nullable()
                ->after('is_online_bookable');
            $table->unsignedInteger('sort_order')
                ->default(0)
                ->after('category_name');
            $table->unsignedSmallInteger('buffer_before_minutes')
                ->default(0)
                ->after('sort_order');
            $table->unsignedSmallInteger('buffer_after_minutes')
                ->default(0)
                ->after('buffer_before_minutes');
            $table->unsignedSmallInteger('min_notice_minutes')
                ->default(0)
                ->after('buffer_after_minutes');
            $table->unsignedSmallInteger('max_advance_days')
                ->nullable()
                ->after('min_notice_minutes');
            $table->unsignedSmallInteger('cancellation_notice_hours')
                ->default(24)
                ->after('max_advance_days');

            $table->index(['tenant_id', 'is_online_bookable']);
            $table->index(['tenant_id', 'category_name', 'sort_order']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('buffer_before_minutes')
                ->default(0)
                ->after('ends_at');
            $table->unsignedSmallInteger('buffer_after_minutes')
                ->default(0)
                ->after('buffer_before_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'buffer_before_minutes',
                'buffer_after_minutes',
            ]);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex('services_tenant_id_is_online_bookable_index');
            $table->dropIndex('services_tenant_id_category_name_sort_order_index');

            $table->dropColumn([
                'is_online_bookable',
                'category_name',
                'sort_order',
                'buffer_before_minutes',
                'buffer_after_minutes',
                'min_notice_minutes',
                'max_advance_days',
                'cancellation_notice_hours',
            ]);
        });
    }
};
