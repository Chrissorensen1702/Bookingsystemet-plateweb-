<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_service', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('service_id');
            $table->text('description')->nullable()->after('name');
            $table->foreignId('service_color_palette_id')
                ->nullable()
                ->after('duration_minutes')
                ->constrained('service_color_palettes')
                ->nullOnDelete();
            $table->string('color', 7)->nullable()->after('service_color_palette_id');
        });

        $services = DB::table('services')
            ->select('id', 'name', 'description', 'duration_minutes', 'service_color_palette_id', 'color')
            ->get();

        foreach ($services as $service) {
            DB::table('location_service')
                ->where('service_id', (int) $service->id)
                ->update([
                    'name' => (string) $service->name,
                    'description' => $service->description !== null ? (string) $service->description : null,
                    'duration_minutes' => (int) $service->duration_minutes,
                    'service_color_palette_id' => $service->service_color_palette_id !== null
                        ? (int) $service->service_color_palette_id
                        : null,
                    'color' => $service->color !== null ? strtoupper((string) $service->color) : null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('location_service', function (Blueprint $table): void {
            $table->dropForeign(['service_color_palette_id']);
            $table->dropColumn([
                'name',
                'description',
                'service_color_palette_id',
                'color',
            ]);
        });
    }
};

