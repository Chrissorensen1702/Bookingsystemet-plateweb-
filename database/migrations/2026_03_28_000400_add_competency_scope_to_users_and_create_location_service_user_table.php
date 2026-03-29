<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('competency_scope', 20)
                ->default('global')
                ->after('is_bookable');
            $table->index(['tenant_id', 'competency_scope'], 'users_tenant_competency_scope_index');
        });

        Schema::create('location_service_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['location_id', 'service_id', 'user_id'],
                'location_service_user_location_service_user_unique'
            );
            $table->index(['user_id', 'location_id'], 'location_service_user_user_location_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_service_user');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_tenant_competency_scope_index');
            $table->dropColumn('competency_scope');
        });
    }
};

