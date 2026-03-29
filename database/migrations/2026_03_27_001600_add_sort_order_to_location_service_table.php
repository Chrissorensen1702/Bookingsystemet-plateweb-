<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_service', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')
                ->nullable()
                ->after('price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('location_service', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
