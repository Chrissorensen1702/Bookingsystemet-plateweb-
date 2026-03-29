<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'user_id'], 'service_user_service_user_unique');
            $table->index(['user_id', 'service_id'], 'service_user_user_service_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_user');
    }
};
