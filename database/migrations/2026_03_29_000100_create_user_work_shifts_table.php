<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_work_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->time('break_starts_at')->nullable();
            $table->time('break_ends_at')->nullable();
            $table->string('work_role', 32)->default('service');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'location_id', 'user_id', 'shift_date'],
                'user_work_shifts_unique_user_day'
            );
            $table->index(['tenant_id', 'location_id', 'shift_date'], 'user_work_shifts_tenant_location_date_idx');
            $table->index(['tenant_id', 'shift_date'], 'user_work_shifts_tenant_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_work_shifts');
    }
};

