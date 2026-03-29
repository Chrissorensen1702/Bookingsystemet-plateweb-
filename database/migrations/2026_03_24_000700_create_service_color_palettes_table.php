<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_color_palettes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('hex_color', 7)->unique();
            $table->unsignedTinyInteger('sort_order')->unique();
            $table->timestamps();
        });

        $now = now();

        DB::table('service_color_palettes')->insert([
            ['key' => 'glaucous', 'name' => 'Glaucous', 'hex_color' => '#5C80BC', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'tuscan_sun', 'name' => 'Tuscan Sun', 'hex_color' => '#E8C547', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'graphite', 'name' => 'Graphite', 'hex_color' => '#30323D', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'charcoal', 'name' => 'Charcoal', 'hex_color' => '#4D5061', 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'ash_grey', 'name' => 'Ash Grey', 'hex_color' => '#CDD1C4', 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'terracotta', 'name' => 'Terracotta', 'hex_color' => '#D66D44', 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'emerald', 'name' => 'Emerald', 'hex_color' => '#2E7D6B', 'sort_order' => 7, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'rosewood', 'name' => 'Rosewood', 'hex_color' => '#B84A7A', 'sort_order' => 8, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'indigo', 'name' => 'Indigo', 'hex_color' => '#6A67CE', 'sort_order' => 9, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'teal', 'name' => 'Teal', 'hex_color' => '#2A9D8F', 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_color_palettes');
    }
};
