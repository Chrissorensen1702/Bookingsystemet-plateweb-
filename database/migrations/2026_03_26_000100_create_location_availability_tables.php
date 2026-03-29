<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_opening_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->index(['location_id', 'weekday']);
            $table->unique(['location_id', 'weekday', 'opens_at', 'closes_at'], 'location_opening_hours_unique_slot');
        });

        Schema::create('location_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 180)->nullable();
            $table->timestamps();

            $table->index(['location_id', 'starts_on', 'ends_on'], 'location_closures_period_index');
        });

        Schema::create('location_date_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table->date('override_date');
            $table->boolean('is_closed')->default(false);
            $table->string('note', 180)->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'override_date'], 'location_date_overrides_unique_day');
            $table->index(['location_id', 'override_date']);
        });

        Schema::create('location_date_override_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_date_override_id')
                ->constrained('location_date_overrides')
                ->cascadeOnDelete();
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->index(['location_date_override_id', 'opens_at'], 'location_override_slots_lookup_index');
            $table->unique(
                ['location_date_override_id', 'opens_at', 'closes_at'],
                'location_override_slots_unique_interval'
            );
        });

        $now = now();
        $rows = [];

        DB::table('locations')
            ->select('id')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $locationId) use (&$rows, $now): void {
                for ($weekday = 1; $weekday <= 7; $weekday++) {
                    $rows[] = [
                        'location_id' => $locationId,
                        'weekday' => $weekday,
                        'opens_at' => '07:00:00',
                        'closes_at' => '22:00:00',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            });

        if ($rows !== []) {
            DB::table('location_opening_hours')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_date_override_slots');
        Schema::dropIfExists('location_date_overrides');
        Schema::dropIfExists('location_closures');
        Schema::dropIfExists('location_opening_hours');
    }
};
