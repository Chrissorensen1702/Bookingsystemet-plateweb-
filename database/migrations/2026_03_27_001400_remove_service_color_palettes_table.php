<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if (Schema::hasColumn('location_service', 'service_color_palette_id')) {
            try {
                Schema::table('location_service', function (Blueprint $table): void {
                    $table->dropForeign(['service_color_palette_id']);
                });
            } catch (\Throwable) {
                // Ignore if foreign key is already removed.
            }

            Schema::table('location_service', function (Blueprint $table): void {
                $table->dropColumn('service_color_palette_id');
            });
        }

        if (Schema::hasColumn('services', 'service_color_palette_id')) {
            try {
                Schema::table('services', function (Blueprint $table): void {
                    $table->dropForeign(['service_color_palette_id']);
                });
            } catch (\Throwable) {
                // Ignore if foreign key is already removed.
            }

            Schema::table('services', function (Blueprint $table): void {
                $table->dropColumn('service_color_palette_id');
            });
        }

        Schema::dropIfExists('service_color_palettes');
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_color_palettes')) {
            Schema::create('service_color_palettes', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->string('hex_color', 7);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! DB::table('service_color_palettes')->exists()) {
            DB::table('service_color_palettes')->insert([
                ['key' => 'glaucous', 'name' => 'Glaucous', 'hex_color' => '#5C80BC', 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'tuscan_sun', 'name' => 'Tuscan Sun', 'hex_color' => '#E8C547', 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'graphite', 'name' => 'Graphite', 'hex_color' => '#30323D', 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'ash_grey', 'name' => 'Ash Grey', 'hex_color' => '#CDD1C4', 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'terracotta', 'name' => 'Terracotta', 'hex_color' => '#A66A4D', 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (! Schema::hasColumn('services', 'service_color_palette_id')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->unsignedBigInteger('service_color_palette_id')->nullable();
            });
        }

        if (! Schema::hasColumn('location_service', 'service_color_palette_id')) {
            Schema::table('location_service', function (Blueprint $table): void {
                $table->unsignedBigInteger('service_color_palette_id')->nullable();
            });
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            try {
                Schema::table('services', function (Blueprint $table): void {
                    $table->foreign('service_color_palette_id')
                        ->references('id')
                        ->on('service_color_palettes')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Ignore if foreign key already exists.
            }

            try {
                Schema::table('location_service', function (Blueprint $table): void {
                    $table->foreign('service_color_palette_id')
                        ->references('id')
                        ->on('service_color_palettes')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Ignore if foreign key already exists.
            }
        }
    }
};
