<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('public_contact_phone', 50)
                ->nullable()
                ->after('public_booking_intro_text');
            $table->string('public_contact_email', 255)
                ->nullable()
                ->after('public_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn([
                'public_contact_phone',
                'public_contact_email',
            ]);
        });
    }
};
