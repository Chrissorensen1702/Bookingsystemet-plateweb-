<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('native_app_tokens', function (Blueprint $table): void {
            $table->string('push_token')->nullable()->after('token_hash');
            $table->string('push_platform', 20)->nullable()->after('push_token');
            $table->boolean('notifications_enabled')->default(false)->after('push_platform');
            $table->timestamp('push_token_updated_at')->nullable()->after('notifications_enabled');

            $table->index(['user_id', 'notifications_enabled'], 'native_app_tokens_user_notifications_index');
        });
    }

    public function down(): void
    {
        Schema::table('native_app_tokens', function (Blueprint $table): void {
            $table->dropIndex('native_app_tokens_user_notifications_index');
            $table->dropColumn([
                'push_token',
                'push_platform',
                'notifications_enabled',
                'push_token_updated_at',
            ]);
        });
    }
};
