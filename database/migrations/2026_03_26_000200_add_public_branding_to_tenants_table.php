<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('public_brand_name', 120)->nullable()->after('name');
            $table->string('public_logo_path')->nullable()->after('public_brand_name');
            $table->string('public_logo_alt', 120)->nullable()->after('public_logo_path');
            $table->string('public_primary_color', 7)->nullable()->after('public_logo_alt');
            $table->string('public_accent_color', 7)->nullable()->after('public_primary_color');
            $table->boolean('show_powered_by')->default(false)->after('public_accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'public_brand_name',
                'public_logo_path',
                'public_logo_alt',
                'public_primary_color',
                'public_accent_color',
                'show_powered_by',
            ]);
        });
    }
};
