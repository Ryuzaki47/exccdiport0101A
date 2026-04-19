<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_notifications', 'read_at')) {
                $table->timestamp('read_at')
                      ->nullable()
                      ->after('dismissed_at')
                      ->comment('Null = unread. Set when student views the notifications page. Does NOT hide the notification.');

                $table->index('read_at', 'admin_notifications_read_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('admin_notifications', 'read_at')) {
                $table->dropIndex('admin_notifications_read_at_index');
                $table->dropColumn('read_at');
            }
        });
    }
};