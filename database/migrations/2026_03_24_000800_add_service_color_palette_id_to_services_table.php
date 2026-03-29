<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table
                ->foreignId('service_color_palette_id')
                ->nullable()
                ->after('duration_minutes')
                ->constrained('service_color_palettes')
                ->nullOnDelete();
        });

        $paletteRows = DB::table('service_color_palettes')
            ->select('id', 'key', 'hex_color')
            ->orderBy('sort_order')
            ->get();

        if ($paletteRows->isEmpty()) {
            return;
        }

        $paletteIdByHex = [];
        $paletteHexById = [];
        $defaultPaletteId = null;

        foreach ($paletteRows as $paletteRow) {
            $hex = strtoupper((string) $paletteRow->hex_color);
            $paletteIdByHex[$hex] = (int) $paletteRow->id;
            $paletteHexById[(int) $paletteRow->id] = $hex;

            if ($paletteRow->key === 'glaucous') {
                $defaultPaletteId = (int) $paletteRow->id;
            }
        }

        if (! $defaultPaletteId) {
            $defaultPaletteId = (int) $paletteRows->first()->id;
        }

        $services = DB::table('services')
            ->select('id', 'color')
            ->get();

        foreach ($services as $service) {
            $rawColor = strtoupper((string) ($service->color ?? ''));
            $paletteId = $paletteIdByHex[$rawColor] ?? $defaultPaletteId;

            DB::table('services')
                ->where('id', (int) $service->id)
                ->update([
                    'service_color_palette_id' => $paletteId,
                    'color' => $paletteHexById[$paletteId] ?? '#5C80BC',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_color_palette_id');
        });
    }
};
