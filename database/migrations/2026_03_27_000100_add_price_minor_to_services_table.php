<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedInteger('price_minor')->nullable()->after('duration_minutes');
        });

        $servicePrices = DB::table('location_service')
            ->select('service_id', DB::raw('MIN(price_minor) as price_minor'))
            ->whereNotNull('price_minor')
            ->groupBy('service_id')
            ->get();

        foreach ($servicePrices as $servicePrice) {
            DB::table('services')
                ->where('id', (int) $servicePrice->service_id)
                ->update([
                    'price_minor' => (int) $servicePrice->price_minor,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('price_minor');
        });
    }
};

