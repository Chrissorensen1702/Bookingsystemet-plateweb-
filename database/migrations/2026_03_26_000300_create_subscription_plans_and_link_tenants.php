<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->boolean('requires_powered_by')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('subscription_plans')->insert([
            [
                'id' => 1,
                'code' => 'starter',
                'name' => 'Starter',
                'requires_powered_by' => true,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'code' => 'growth',
                'name' => 'Growth',
                'requires_powered_by' => false,
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'code' => 'scale',
                'name' => 'Scale',
                'requires_powered_by' => false,
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreignId('plan_id')
                ->default(1)
                ->after('timezone')
                ->constrained('subscription_plans')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
        });

        Schema::dropIfExists('subscription_plans');
    }
};

